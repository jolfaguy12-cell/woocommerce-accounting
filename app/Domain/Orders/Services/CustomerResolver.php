<?php

namespace App\Domain\Orders\Services;

use App\Domain\Accounting\Models\Party;

/**
 * Resolves the hub's customer/billing data on an order payload to a Party.
 * Registered customers (hub_customer_id > 0) are deduped by that id; guest
 * checkouts are deduped by phone when available, otherwise recorded as a
 * new party per order (matches how WooCommerce treats guest checkouts —
 * they were never a single customer entity to begin with).
 */
class CustomerResolver
{
    public function resolve(array $payload): ?int
    {
        $billing = (array) ($payload['billing'] ?? []);
        $name = trim(($billing['first_name'] ?? '').' '.($billing['last_name'] ?? '')) ?: null;
        $phone = $billing['phone'] ?? null;
        $hubCustomerId = (int) ($payload['customer_id'] ?? 0);

        if ($hubCustomerId > 0) {
            $party = Party::firstOrNew(['hub_customer_id' => $hubCustomerId]);
            $party->type = 'customer';
            $party->name = $name ?: ($party->name ?: "مشتری #{$hubCustomerId}");
            $party->phone = $phone ?: $party->phone;
            $party->save();

            return $party->id;
        }

        if (! $name && ! $phone) {
            return null; // guest order with nothing to identify a customer by
        }

        $party = $phone
            ? Party::firstOrNew(['type' => 'customer', 'phone' => $phone])
            : new Party(['type' => 'customer']);
        $party->name = $name ?: 'مهمان';
        $party->phone = $phone ?: $party->phone;
        $party->save();

        return $party->id;
    }
}
