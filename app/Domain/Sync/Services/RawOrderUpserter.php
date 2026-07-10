<?php

namespace App\Domain\Sync\Services;

use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Sync\Models\RawOrder;
use Illuminate\Support\Carbon;

class RawOrderUpserter
{
    /**
     * Idempotently store a raw hub order payload. Unchanged payloads are
     * skipped; payloads older than what we already hold (stale replays,
     * out-of-order webhooks) never overwrite newer data.
     */
    public function upsert(int $hubOrderId, array $payload, string $via): RawOrder
    {
        $hash = hash('sha256', json_encode($payload));
        $modifiedAt = $this->extractModifiedAt($payload);

        $existing = RawOrder::firstWhere('hub_order_id', $hubOrderId);

        if ($existing) {
            if ($existing->payload_hash === $hash) {
                return $existing;
            }
            // Compare via payload timestamps (both GMT) — the DB column round-trips
            // through the app timezone and would skew the comparison.
            $existingModifiedAt = $this->extractModifiedAt($existing->payload);
            if ($modifiedAt && $existingModifiedAt && $modifiedAt->lt($existingModifiedAt)) {
                return $existing; // stale
            }

            $existing->update([
                'payload' => $payload,
                'payload_hash' => $hash,
                'fetched_via' => $via,
                'hub_modified_at' => $modifiedAt,
                'received_at' => now(),
            ]);

            return $existing;
        }

        return RawOrder::create([
            'hub_order_id' => $hubOrderId,
            'payload' => $payload,
            'payload_hash' => $hash,
            'fetched_via' => $via,
            'hub_modified_at' => $modifiedAt,
            'received_at' => now(),
        ]);
    }

    private function extractModifiedAt(array $payload): ?Carbon
    {
        $raw = $payload['date_updated_gmt'] ?? $payload['date_modified_gmt'] ?? $payload['date_modified'] ?? $payload['updated_at'] ?? null;

        return JalaliPeriod::parseHubGmt($raw);
    }
}
