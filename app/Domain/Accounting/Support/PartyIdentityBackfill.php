<?php

namespace App\Domain\Accounting\Support;

use Illuminate\Support\Facades\DB;

/**
 * Backfills derived from data that already exists on `parties`:
 *   - one party_roles row per legacy parties.type value
 *   - parties.normalized_phone from parties.phone
 *
 * Both are idempotent and re-runnable, and both are deliberately written with
 * the query builder (no models, no events) so they behave identically whether
 * they are called from a migration or from `php artisan parties:backfill-roles`.
 *
 * Re-runnability is not a nicety: production keeps minting customer parties from
 * order sync, so whatever a migration backfilled during development is already
 * stale by the time the code deploys — the command is run again at that point.
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
