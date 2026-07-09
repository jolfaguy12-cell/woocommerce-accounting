<?php

namespace App\Domain\Sync\Services;

use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Products\Services\ProductSyncer;
use App\Domain\Sync\Models\ReviewItem;
use App\Domain\Sync\Models\WebhookEvent;
use RuntimeException;
use Throwable;

class WebhookProcessor
{
    public function __construct(
        private readonly HubClient $hub,
        private readonly RawOrderUpserter $orders,
    ) {}

    /**
     * Process one stored webhook event. Throws on failure so the queue can
     * retry; after max attempts the event is parked as dead and a sync_error
     * review item is opened (dead-letter behaviour).
     */
    public function process(WebhookEvent $event): void
    {
        if (in_array($event->status, ['done', 'dead'], true)) {
            return;
        }

        $event->update(['status' => 'processing', 'attempts' => $event->attempts + 1]);

        try {
            match (true) {
                str_starts_with($event->event_type, 'order.') => $this->handleOrder($event),
                str_starts_with($event->event_type, 'product.') => $this->handleProduct($event),
                default => throw new RuntimeException("Unknown hub event type [{$event->event_type}]."),
            };

            $event->update(['status' => 'done', 'processed_at' => now()]);
        } catch (Throwable $e) {
            $isFinal = $event->attempts >= (int) config('hub.webhook_max_attempts');

            $event->update([
                'status' => $isFinal ? 'dead' : 'failed',
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            if ($isFinal) {
                ReviewItem::open('sync_error', $event, [
                    'event_type' => $event->event_type,
                    'error' => mb_substr($e->getMessage(), 0, 500),
                ]);

                return; // dead-lettered; stop retrying
            }

            throw $e;
        }
    }

    private function handleOrder(WebhookEvent $event): void
    {
        if ($event->event_type === 'order.deleted') {
            // Mirror deletions are recorded but never remove financial history.
            ReviewItem::open('sync_error', $event, ['note' => 'order.deleted received; manual review required']);

            return;
        }

        $payload = $event->payload;
        $order = $this->embeddedEntity($payload, 'order');
        $orderId = $order['id'] ?? $payload['entity_id'] ?? $payload['order_id'] ?? $payload['id']
            ?? $payload['data']['id'] ?? null;

        if (! $orderId) {
            throw new RuntimeException('Webhook payload carries no order id.');
        }

        // Thin payloads only reference the order; pull the full row from the hub.
        $order ??= $this->hub->order((int) $orderId);

        app(OrderIngestPipeline::class)->ingest((int) $orderId, $order, 'webhook');
    }

    private function handleProduct(WebhookEvent $event): void
    {
        if ($event->event_type === 'product.deleted') {
            ReviewItem::open('sync_error', $event, ['note' => 'product.deleted received; manual review required']);

            return;
        }

        $payload = $event->payload;
        $productId = $payload['product']['id'] ?? $payload['entity_id'] ?? $payload['product_id']
            ?? $payload['id'] ?? $payload['data']['id'] ?? null;

        if (! $productId) {
            throw new RuntimeException('Webhook payload carries no product id.');
        }

        // Always re-fetch: webhook payloads may be thin, and variations need pulling anyway.
        app(ProductSyncer::class)
            ->sync((int) $productId, 'webhook', $event->correlation_id);
    }

    /**
     * Extract a full embedded entity from either the legacy test shape
     * ({order: {...}}) or the hub envelope ({entity_id, data: {...}}).
     * A data blob holding only an id counts as thin — return null so the
     * caller re-fetches the full row from the hub.
     */
    private function embeddedEntity(array $payload, string $key): ?array
    {
        if (is_array($payload[$key] ?? null) && isset($payload[$key]['id'])) {
            return $payload[$key];
        }

        $data = $payload['data'] ?? null;
        if (is_array($data) && isset($data['id']) && count($data) > 1) {
            return $data;
        }

        return null;
    }
}
