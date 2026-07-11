<?php

namespace App\Domain\Orders\Services;

use App\Domain\Accounting\Models\Party;
use App\Domain\Orders\Support\BillingAddressFormatter;

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
            $party->type = 'customer';
            $party->name = $name ?: ($party->name ?: "مشتری #{$hubCustomerId}");
            $party->phone = $phone ?: $party->phone;
            $party->email = $email ?: $party->email;
            $party->address = $address ?: $party->address;
            $party->save();

            return $party->id;
        }

        if (! $name && ! $phone) {
            return $existingPartyId; // payload lost its identifying info on a resync — keep whatever it already resolved to
        }

        if ($phone) {
            $party = Party::where('type', 'customer')->where('phone', $phone)->first();

            if (! $party && $existingPartyId) {
                $linked = Party::find($existingPartyId);
                if ($linked && $linked->type === 'customer' && $linked->phone === null) {
                    $party = $linked; // same order, same person — it just gave us a phone number this time
                }
            }

            $party ??= new Party(['type' => 'customer']);
        } else {
            $party = Party::firstOrNew(['type' => 'customer', 'phone' => null, 'name' => $name]);
        }

        $party->type = 'customer';
        $party->name = $name ?: ($party->name ?: 'مهمان');
        $party->phone = $phone ?: $party->phone;
        $party->email = $email ?: $party->email;
        $party->address = $address ?: $party->address;
        $party->save();

        return $party->id;
    }

    private function normalizeName(string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return $name !== '' ? $name : null;
    }

    /**
     * The hub's billing phone shows up in several equivalent forms for the
     * same real number (+989121234567 / 00989121234567 / 9121234567 /
     * 09121234567) — normalized to a single canonical form so format drift
     * alone never creates a duplicate customer.
     */
    private function normalizePhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        // Billing phones sometimes arrive in Persian/Arabic-Indic digits —
        // \D wouldn't touch those, so left alone they'd strip to nothing below.
        $ascii = strtr($phone, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        $digits = preg_replace('/\D/', '', $ascii) ?? '';

        if (str_starts_with($digits, '0098')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '98') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 10 && $digits[0] === '9') {
            $digits = '0'.$digits;
        }

        // Not a single recognizable mobile number (e.g. two numbers pasted
        // into one field) — don't guess, fall back to the original value.
        if (strlen($digits) !== 11) {
            return trim($phone) !== '' ? trim($phone) : null;
        }

        return $digits;
    }
}
