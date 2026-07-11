<?php

namespace App\Domain\Sync\Support;

/**
 * The hub normally mirrors an order's meta as a flat `meta` map, but orders
 * that never went through its usual webhook/normalize path (e.g. created
 * directly in wp-admin) can arrive in WooCommerce's raw REST shape instead —
 * `meta_data`: a list of {key, value} pairs. Read through here so every meta
 * lookup (channel source detection, commission/discount metadata) works
 * regardless of which shape the hub happened to send.
 */
class RawOrderMeta
{
    public static function get(array $payload, string $key): mixed
    {
        if (array_key_exists($key, $payload['meta'] ?? [])) {
            return $payload['meta'][$key];
        }

        foreach ((array) ($payload['meta_data'] ?? []) as $entry) {
            if (($entry['key'] ?? null) === $key) {
                return $entry['value'] ?? null;
            }
        }

        return null;
    }
}
