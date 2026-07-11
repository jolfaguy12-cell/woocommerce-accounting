<?php

namespace App\Domain\Orders\Services;

use App\Domain\Accounting\Models\Party;
use App\Domain\Orders\Support\BillingAddressFormatter;

/**
 * Resolves the hub's customer/billing data on an order payload to a Party.
 * Registered customers (hub_customer_id > 0) are deduped by that id. Guest
 * checkouts are deduped by phone when available; without a phone, they're
 * deduped by exact (normalized) name instead — so e.g. every "ملیکا خلیلی"
 * guest order groups under one customer instead of becoming a new party
 * per order. Two different real people who share a name and never give a
 * phone number will still be merged into one row; that's an accepted
 * trade-off (confirmed with the user) since there is no better identifier.
 */
class CustomerResolver
{
    public function resolve(array $payload): ?int
    {
        $billing = (array) ($payload['billing'] ?? []);
        $name = $this->normalizeName(trim(($billing['first_name'] ?? '').' '.($billing['last_name'] ?? '')));
        $phone = $billing['phone'] ?? null;
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
            return null; // guest order with nothing to identify a customer by
        }

        $party = $phone
            ? Party::firstOrNew(['type' => 'customer', 'phone' => $phone])
            : Party::firstOrNew(['type' => 'customer', 'phone' => null, 'name' => $name]);
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
}
