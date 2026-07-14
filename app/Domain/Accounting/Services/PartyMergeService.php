<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Models\PartyAlias;
use App\Domain\Accounting\Models\PartyRole;
use App\Domain\Accounting\Support\PartyRoleType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * «ادغام طرف حساب‌ها» — two rows, one real person.
 *
 * The line this service will not cross: it never touches `journal_lines`. The
 * naive merge repoints every posted line at the survivor, which is editing
 * history — the entries were posted against the id that existed at the time,
 * they reconcile against it, and an audit that re-reads them a year from now
 * must find what was actually posted, not what we later wished had been.
 *
 * So the absorbed party is not deleted and its id is not reused. Three things
 * happen instead:
 *
 *   1. OPERATIONAL records move. Orders, invoices, payments, loans, cheques and
 *      the like are live business records, not posted history — a merge is
 *      exactly the statement that they belong to the survivor.
 *   2. LEDGER history stays put, and is aggregated. `Party::identityIds()`
 *      returns the survivor plus every id merged into it, and every balance and
 *      statement sums over that set (see PartyLedgerService). The survivor's
 *      profile therefore shows the complete history of both, while every journal
 *      line still points exactly where it was posted.
 *   3. IDENTITY is recorded. A `party_aliases` row snapshots the absorbed party,
 *      the reason, who did it and when; the absorbed party is flagged
 *      `merged_into_id` and drops out of every list via `notMerged()`.
 *
 * The merge is reversible in the only sense that matters: nothing was destroyed.
 */
class PartyMergeService
{
    /**
     * Operational foreign keys that follow the identity. Deliberately explicit —
     * a merge that silently missed a table would strand records on a dead party,
     * and a generic "find every FK to parties" would sooner or later sweep up
     * `journal_lines` too.
     *
     * @var array<string, string>
     */
    private const OPERATIONAL_KEYS = [
        'orders' => 'customer_party_id',
        'purchase_invoices' => 'supplier_party_id',
        'expenses' => 'party_id',
        'loans' => 'party_id',
        'cheques' => 'party_id',
        'party_payments' => 'party_id',
        'credit_orders' => 'party_id',
        'party_offsets' => 'party_id',
        'partner_operations' => 'party_id',
        'account_transactions' => 'party_id',
        'party_bank_accounts' => 'party_id',
        'bad_debt_write_offs' => 'party_id',
        'supplier_credit_adjustments' => 'party_id',
        'users' => 'party_id',
    ];

    /**
     * Identity fields the survivor inherits — but only where it has none of its own.
     *
     * `hub_customer_id` is NOT here, and must not be: the column is UNIQUE, so
     * copying it onto the survivor while the absorbed party still holds it throws a
     * constraint violation and the whole merge fails. It is MOVED instead, by
     * moveHubCustomerId() below. (It is still captured in the alias snapshot, so the
     * absorbed party's original external identity remains auditable.)
     */
    private const IDENTITY_FIELDS = [
        'phone', 'email', 'address', 'telegram_id', 'national_id',
        'company_national_id', 'tax_id', 'registration_id',
    ];

    /**
     * @param  Party  $survivor  the identity that remains
     * @param  Party  $absorbed  the duplicate, kept forever but no longer separate
     */
    public function merge(Party $survivor, Party $absorbed, string $reason, ?User $by = null): Party
    {
        $this->assertMergeable($survivor, $absorbed);

        return DB::transaction(function () use ($survivor, $absorbed, $reason, $by) {
            PartyAlias::create([
                'party_id' => $survivor->id,
                'merged_party_id' => $absorbed->id,
                'reason' => $reason,
                'snapshot' => $this->snapshot($absorbed),
                'merged_at' => now(),
                'merged_by' => $by?->id,
            ]);

            $this->moveOperationalRecords($survivor, $absorbed);
            $this->moveHubCustomerId($survivor, $absorbed);
            $this->inheritIdentityFields($survivor, $absorbed);
            // Before unionRoles(), which would otherwise create a blank profile
            // for a role whose real profile is sitting on the absorbed party.
            $this->moveOrphanedProfiles($survivor, $absorbed);
            $this->unionRoles($survivor, $absorbed, $by);

            // The absorbed party keeps every journal line it ever had. It just
            // stops being a party anyone can transact with.
            $absorbed->forceFill(['merged_into_id' => $survivor->id])->save();

            foreach ($absorbed->roles()->active()->get() as $role) {
                $absorbed->deactivateRole($role->role, $by?->id);
            }

            return $survivor->refresh();
        });
    }

    public function assertMergeable(Party $survivor, Party $absorbed): void
    {
        if ($survivor->id === $absorbed->id) {
            throw new InvalidArgumentException('یک طرف حساب را نمی‌توان با خودش ادغام کرد.');
        }

        if ($absorbed->isMerged()) {
            throw new InvalidArgumentException('این طرف حساب قبلاً ادغام شده است.');
        }

        if ($survivor->isMerged()) {
            throw new InvalidArgumentException('طرف حساب مقصد خودش ادغام شده است؛ ابتدا طرف حساب اصلی را انتخاب کنید.');
        }
    }

    /**
     * Live records move; posted history does not. `journal_lines` is absent from
     * OPERATIONAL_KEYS by design, and this is the only place that could have
     * added it.
     */
    private function moveOperationalRecords(Party $survivor, Party $absorbed): void
    {
        foreach (self::OPERATIONAL_KEYS as $table => $column) {
            DB::table($table)->where($column, $absorbed->id)->update([$column => $survivor->id]);
        }

        // expenses carries a second party reference: who PAID for it.
        DB::table('expenses')->where('funded_by_party_id', $absorbed->id)
            ->update(['funded_by_party_id' => $survivor->id]);

        // External ids are unique per (source, external_id): move the ones that do
        // not collide, and leave a collision where it is rather than losing the
        // fact that both parties claimed the same external identity.
        foreach ($absorbed->externalIds as $external) {
            try {
                DB::transaction(fn () => $external->update(['party_id' => $survivor->id]));
            } catch (QueryException) {
                // The survivor already owns this external id — nothing to move.
            }
        }
    }

    /**
     * Role profiles are `unique(party_id)`, so they cannot be summed — they move
     * or they stay. A profile moves only when the survivor has none of its own:
     * an employee's base salary, a supplier's payment terms and a customer's
     * credit limit are single facts about the person, and the survivor's own
     * version of a fact always wins over the duplicate's.
     */
    private function moveOrphanedProfiles(Party $survivor, Party $absorbed): void
    {
        $profiles = [
            'customer_profiles',
            'supplier_profiles',
            'partner_profiles',
            'employees',
        ];

        foreach ($profiles as $table) {
            $survivorHasOne = DB::table($table)->where('party_id', $survivor->id)->exists();

            if ($survivorHasOne) {
                continue; // the survivor's own profile stands; the duplicate's stays put
            }

            DB::table($table)->where('party_id', $absorbed->id)->update(['party_id' => $survivor->id]);
        }

        $survivor->unsetRelation('customerProfile')
            ->unsetRelation('supplierProfile')
            ->unsetRelation('partnerProfile')
            ->unsetRelation('employee');
    }

    /**
     * The WooCommerce customer id follows the identity — MOVED, not copied.
     *
     * It is the party's external identity, and `parties.hub_customer_id` is UNIQUE.
     * Copying it onto the survivor while the absorbed party still holds it violates
     * that index and takes the entire merge down with it — which is precisely what
     * happened to every merge of a hub-synced customer.
     *
     * Moving it is also what makes the sync safe. CustomerResolver looks this id up
     * to decide which party an order belongs to; if it stayed on the absorbed party,
     * the resolver's `notMerged()` lookup would find nothing and mint a THIRD party
     * for the same person — the duplicate would come back, under a new id, on the
     * very next order.
     *
     * If the survivor already has its own hub id, the absorbed one stays where it is
     * (two real WooCommerce accounts, one real person — that happens). The resolver
     * follows the merge chain in that case, so the order still lands on the survivor.
     */
    private function moveHubCustomerId(Party $survivor, Party $absorbed): void
    {
        if (blank($absorbed->hub_customer_id) || filled($survivor->hub_customer_id)) {
            return;
        }

        $hubId = $absorbed->hub_customer_id;

        // Clear it from the absorbed party FIRST — the unique index does not care that
        // the row it collides with is about to stop being a live identity.
        $absorbed->forceFill(['hub_customer_id' => null])->save();
        $survivor->forceFill(['hub_customer_id' => $hubId])->save();
    }

    /** The survivor never loses data it already has; it only fills its own gaps. */
    private function inheritIdentityFields(Party $survivor, Party $absorbed): void
    {
        $fill = [];

        foreach (self::IDENTITY_FIELDS as $field) {
            if (blank($survivor->{$field}) && filled($absorbed->{$field})) {
                $fill[$field] = $absorbed->{$field};
            }
        }

        if ($fill !== []) {
            $survivor->fill($fill)->save();
        }
    }

    /**
     * Roles are a union: a customer merged into a supplier is now both, and the
     * profile each role carries is created by activateRole() if it is missing.
     * The absorbed party's own profile rows stay where they are — they are its
     * history, and the survivor's are its own.
     */
    private function unionRoles(Party $survivor, Party $absorbed, ?User $by): void
    {
        $absorbed->roles()->active()->get()
            ->each(fn (PartyRole $role) => $survivor->activateRole(
                PartyRoleType::coerce($role->role),
                $by?->id,
            ));
    }

    /** @return array<string, mixed> */
    private function snapshot(Party $absorbed): array
    {
        return [
            'id' => $absorbed->id,
            'name' => $absorbed->name,
            'party_kind' => $absorbed->party_kind,
            'roles' => $absorbed->activeRoles()->pluck('role')->all(),
            // Explicit: it is not in IDENTITY_FIELDS (it is moved, not copied — see
            // moveHubCustomerId), and the snapshot is the only remaining record that
            // this party ever held it.
            'hub_customer_id' => $absorbed->hub_customer_id,
            ...$absorbed->only(self::IDENTITY_FIELDS),
        ];
    }
}
