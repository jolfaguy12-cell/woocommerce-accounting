<?php

namespace App\Domain\Orders\Services;

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Support\PartyRoleType;
use App\Domain\Accounting\Support\PhoneNormalizer;
use App\Domain\Orders\Support\BillingAddressFormatter;
use App\Domain\Sync\Models\ReviewItem;

/**
 * Resolves the hub's customer/billing data on an order payload to a Party.
 * Registered customers (hub_customer_id > 0) are deduped by that id. Guest
 * checkouts are deduped by phone (normalized — see normalizePhone()) when
 * available; without a phone, they're deduped by exact (normalized) name
 * instead — so e.g. every "ملیکا خلیلی" guest order groups under one
 * customer instead of becoming a new party per order. Two different real
 * people who share a name and never give a phone number will still be
 * merged into one row; that's an accepted trade-off (confirmed with the
 * user) since there is no better identifier.
 */
class CustomerResolver
{
    /**
     * $existingPartyId: the order's currently-linked party, if this is a
     * re-normalize (e.g. a WooCommerce edit resynced the order). Some orders
     * are placed with no phone/email and get one added later purely via an
     * order edit — without this, the phone-keyed lookup below would find no
     * match (the original party was saved with phone=null) and mint a brand
     * new party, silently orphaning the old one every time this happens.
     * Reusing the already-linked party instead means "the same order just
     * learned a phone number" updates it in place rather than duplicating it.
     */
    public function resolve(array $payload, ?int $existingPartyId = null): ?int
    {
        $billing = (array) ($payload['billing'] ?? []);
        $name = $this->normalizeName(trim(($billing['first_name'] ?? '').' '.($billing['last_name'] ?? '')));
        $phone = $this->normalizePhone($billing['phone'] ?? null);
        $email = $billing['email'] ?? null;
        $address = BillingAddressFormatter::format($billing);
        $hubCustomerId = (int) ($payload['customer_id'] ?? 0);

        if ($hubCustomerId > 0) {
            $party = Party::firstOrNew(['hub_customer_id' => $hubCustomerId]);
            $party->name = $name ?: ($party->name ?: "مشتری #{$hubCustomerId}");
            $party->phone = $phone ?: $party->phone;
            $party->email = $email ?: $party->email;
            $party->address = $address ?: $party->address;
            $party->save();

            $this->ensureCustomerRole($party);

            return $party->id;
        }

        if (! $name && ! $phone) {
            return $existingPartyId; // payload lost its identifying info on a resync — keep whatever it already resolved to
        }

        $isNewParty = false;

        if ($phone) {
            $party = Party::withRole(PartyRoleType::Customer)->where('phone', $phone)->first();

            if (! $party && $existingPartyId) {
                $linked = Party::find($existingPartyId);
                if ($linked && $linked->hasRole(PartyRoleType::Customer) && $linked->phone === null) {
                    $party = $linked; // same order, same person — it just gave us a phone number this time
                }
            }

            if (! $party) {
                $party = new Party;
                $isNewParty = true;
            }
        } else {
            $party = Party::withRole(PartyRoleType::Customer)->whereNull('phone')->where('name', $name)->first()
                ?? new Party(['phone' => null, 'name' => $name]);
        }

        $party->name = $name ?: ($party->name ?: 'مهمان');
        $party->phone = $phone ?: $party->phone;
        $party->email = $email ?: $party->email;
        $party->address = $address ?: $party->address;
        $party->save();

        $this->ensureCustomerRole($party);

        // Two orders under the exact same name but two different phone
        // numbers might be the same person checking out from a different
        // channel (marketplaces often only pass the number used on that
        // platform) — or two different real people who share a common name.
        // Never guess: flag it for a human to decide instead of silently
        // scattering one customer's history across parties (see order 6632 /
        // party 1092 vs 1093).
        if ($isNewParty && $name) {
            $this->flagPossibleDuplicate($party, $name, $phone);
        }

        return $party->id;
    }

    /**
     * A party that already exists as something else (a supplier we also sell to,
     * say) now buys from us: give it the customer role rather than minting a
     * second party for the same real person. A no-op when the role is already
     * active, so the common path costs one indexed lookup.
     */
    private function ensureCustomerRole(Party $party): void
    {
        if (! $party->hasRole(PartyRoleType::Customer)) {
            $party->activateRole(PartyRoleType::Customer);
        }
    }

    private function flagPossibleDuplicate(Party $newParty, string $name, string $phone): void
    {
        $existing = Party::withRole(PartyRoleType::Customer)
            ->where('name', $name)
            ->where('id', '!=', $newParty->id)
            ->whereNotNull('phone')
            ->where('phone', '!=', $phone)
            ->first();

        if (! $existing) {
            return;
        }

        // $newParty is only ever "new" the moment it's first created (every
        // later resolve() call finds it by phone instead), so subject_id
        // alone is enough to guarantee this fires at most once per party.
        $alreadyOpen = ReviewItem::where('type', 'possible_duplicate_customer')
            ->where('subject_type', 'party')
            ->where('subject_id', $newParty->id)
            ->where('status', 'open')
            ->exists();

        if ($alreadyOpen) {
            return;
        }

        ReviewItem::open('possible_duplicate_customer', $newParty, [
            'name' => $name,
            'new_party_id' => $newParty->id,
            'new_party_phone' => $phone,
            'existing_party_id' => $existing->id,
            'existing_party_phone' => $existing->phone,
        ]);
    }

    private function normalizeName(string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return $name !== '' ? $name : null;
    }

    /** @see PhoneNormalizer — shared with party identity/duplicate detection, which must agree on what "the same number" means. */
    private function normalizePhone(?string $phone): ?string
    {
        return PhoneNormalizer::normalize($phone);
    }
}
