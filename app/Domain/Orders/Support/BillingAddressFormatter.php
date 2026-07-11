<?php

namespace App\Domain\Orders\Support;

/** Shared by CustomerResolver (live ingest) and the customer-profile backfill (historical). */
class BillingAddressFormatter
{
    /** City/postcode text only — WooCommerce's "state" field here is a raw province code, not human-readable. */
    public static function format(array $billing): ?string
    {
        $parts = array_filter([
            $billing['address_1'] ?? null,
            $billing['address_2'] ?? null,
            $billing['city'] ?? null,
            $billing['postcode'] ?? null,
        ], fn ($v) => trim((string) $v) !== '');

        return $parts ? implode('، ', $parts) : null;
    }
}
