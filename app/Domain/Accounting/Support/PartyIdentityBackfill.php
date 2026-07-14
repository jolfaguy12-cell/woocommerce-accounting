<?php

namespace App\Domain\Accounting\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Backfills derived from data that used to live on `parties`:
 *   - one party_roles row per legacy parties.type value
 *   - the role columns (credit_limit, is_wholesale, shop_name, …) into role profiles
 *   - parties.normalized_phone from parties.phone
 *
 * ⚠ MIGRATION-ONLY. `roles()` and `profiles()` read columns that no longer exist:
 * they are called by the migrations that ran BEFORE
 * 2026_07_14_100000_drop_legacy_party_columns, and one final time by the drop
 * migration itself. Calling either from application code now would fail on an
 * unknown column — which is why the two artisan commands that used to wrap them
 * are gone. Nothing outside `database/migrations` may call them.
 *
 * (`normalizedPhones()` reads `phone`, which is still there, and remains safe.)
 *
 * All three are idempotent and written with the query builder (no models, no
 * events) so they behave identically wherever they run.
 */
class PartyIdentityBackfill
{
    /** @return int number of party_roles rows created */
    public static function roles(): int
    {
        $created = 0;
        $now = now();

        DB::table('parties')
            ->select('id', 'type', 'created_at')
            // chunkById (not chunk): the insert below removes rows from this
            // very filter, which would make an offset-paged chunk skip records.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('party_roles')
                ->whereColumn('party_roles.party_id', 'parties.id')
                ->whereColumn('party_roles.role', 'parties.type'))
            ->chunkById(500, function ($parties) use (&$created, $now) {
                $rows = $parties->map(fn ($party) => [
                    'party_id' => $party->id,
                    'role' => $party->type,
                    'is_active' => true,
                    'activated_at' => $party->created_at ?: $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                // insertOrIgnore, not insert: the (party_id, role) unique index is
                // the real guard, and a concurrent Party::created hook may have
                // written the same row a millisecond earlier.
                $created += DB::table('party_roles')->insertOrIgnore($rows);
            });

        return $created;
    }

    /**
     * Move the role-specific columns that were squatting on `parties` into their
     * role profiles, and the single supplier bank_account_number into the
     * counterparty bank-account table.
     *
     * Only ever INSERTS a missing profile — it never updates one that already
     * exists. Re-running this after the UI has edited a profile must not revert
     * that edit to the stale legacy column it was copied from.
     *
     * @return array{customer_profiles: int, supplier_profiles: int, bank_accounts: int}
     */
    public static function profiles(): array
    {
        $stats = ['customer_profiles' => 0, 'supplier_profiles' => 0, 'bank_accounts' => 0];
        $now = now();

        self::partiesWithRole(PartyRoleType::Customer)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('customer_profiles')
                ->whereColumn('customer_profiles.party_id', 'parties.id'))
            ->chunkById(500, function ($parties) use (&$stats, $now) {
                $stats['customer_profiles'] += DB::table('customer_profiles')->insertOrIgnore(
                    $parties->map(fn ($party) => [
                        'party_id' => $party->id,
                        'credit_limit' => $party->credit_limit ?? 0,
                        'is_wholesale' => $party->is_wholesale ?? false,
                        'wholesale_labeled_at' => $party->wholesale_labeled_at,
                        'wholesale_labeled_by' => $party->wholesale_labeled_by,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });

        self::partiesWithRole(PartyRoleType::Supplier)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('supplier_profiles')
                ->whereColumn('supplier_profiles.party_id', 'parties.id'))
            ->chunkById(500, function ($parties) use (&$stats, $now) {
                $stats['supplier_profiles'] += DB::table('supplier_profiles')->insertOrIgnore(
                    $parties->map(fn ($party) => [
                        'party_id' => $party->id,
                        'shop_name' => $party->shop_name,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });

        // The old single `parties.bank_account_number` becomes the party's first
        // counterparty bank account. Skipped entirely for a party that already
        // has one, so re-running never appends a duplicate.
        DB::table('parties')
            ->select('id', 'name', 'bank_account_number')
            ->whereNotNull('bank_account_number')
            ->where('bank_account_number', '!=', '')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('party_bank_accounts')
                ->whereColumn('party_bank_accounts.party_id', 'parties.id'))
            ->chunkById(500, function ($parties) use (&$stats, $now) {
                $stats['bank_accounts'] += DB::table('party_bank_accounts')->insertOrIgnore(
                    $parties->map(fn ($party) => [
                        'party_id' => $party->id,
                        'account_holder' => $party->name,
                        'account_number' => $party->bank_account_number,
                        'is_default' => true,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });

        return $stats;
    }

    private static function partiesWithRole(PartyRoleType $role): Builder
    {
        return DB::table('parties')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('party_roles')
                ->whereColumn('party_roles.party_id', 'parties.id')
                ->where('party_roles.role', $role->value)
                ->where('party_roles.is_active', true));
    }

    /** @return int number of parties whose normalized_phone was filled in */
    public static function normalizedPhones(): int
    {
        $updated = 0;

        DB::table('parties')
            ->select('id', 'phone')
            ->whereNotNull('phone')
            ->whereNull('normalized_phone')
            ->chunkById(500, function ($parties) use (&$updated) {
                foreach ($parties as $party) {
                    $normalized = PhoneNormalizer::normalize($party->phone);

                    if ($normalized === null) {
                        continue;
                    }

                    DB::table('parties')->where('id', $party->id)->update(['normalized_phone' => $normalized]);
                    $updated++;
                }
            });

        return $updated;
    }
}
