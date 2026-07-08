<?php

namespace App\Domain\Channels\Services;

use App\Domain\Channels\Models\ChannelSource;
use App\Domain\Sync\Models\ReviewItem;

/**
 * Data-driven source→channel resolution. Never throws for a new source:
 * unknown values are stored, queued for review, and orders keep flowing
 * with a safe fallback (README §11).
 */
class ChannelResolver
{
    /** Payload fields carrying source markers, in priority order. */
    private const SOURCE_FIELDS = [
        'order_source',
        'source_channel',
        'external_marketplace',
        'created_via',
    ];

    private const META_SOURCE_KEYS = [
        '_wc_order_attribution_utm_source',
        '_wc_order_attribution_source_type',
    ];

    public function resolve(array $payload): ChannelSource
    {
        [$rawValue, $field] = $this->extractRawSource($payload);

        $source = ChannelSource::firstOrCreate(['raw_value' => $rawValue], [
            'raw_signature' => ['field' => $field],
            'status' => 'unknown',
            'first_seen_at' => now(),
        ]);

        if ($source->wasRecentlyCreated && $source->status === 'unknown') {
            ReviewItem::open('unknown_source', $source, ['raw_value' => $rawValue, 'field' => $field]);
        }

        $source->increment('order_count');

        return $source;
    }

    private function extractRawSource(array $payload): array
    {
        foreach (self::SOURCE_FIELDS as $field) {
            $value = $payload[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return [mb_strtolower(trim($value)), $field];
            }
        }

        foreach (self::META_SOURCE_KEYS as $key) {
            $value = $payload['meta'][$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return [mb_strtolower(trim($value)), "meta.{$key}"];
            }
        }

        return ['unknown', null];
    }
}
