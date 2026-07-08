<?php

namespace App\Domain\Sync\Services;

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
        $order = is_array($payload['order'] ?? null) && isset($payload['order']['id']) ? $payload['order'] : null;
        $orderId = $order['id'] ?? $payload['order_id'] ?? $payload['id'] ?? null;

        if (! $orderId) {
            throw new RuntimeException('Webhook payload carries no order id.');
        }

        // Thin payloads only reference the order; pull the full row from the hub.
        $order ??= $this->hub->order((int) $orderId);

        $this->orders->upsert((int) $orderId, $order, 'webhook');
    }

    private function handleProduct(WebhookEvent $event): void
    {
        if ($event->event_type === 'product.deleted') {
            ReviewItem::open('sync_error', $event, ['note' => 'product.deleted received; manual review required']);

            return;
        }

        $payload = $event->payload;
        $productId = $payload['product']['id'] ?? $payload['product_id'] ?? $payload['id'] ?? null;

        if (! $productId) {
            throw new RuntimeException('Webhook payload carries no product id.');
        }

        // Always re-fetch: webhook payloads may be thin, and variations need pulling anyway.
        app(ProductSyncer::class)
            ->sync((int) $productId, 'webhook', $event->correlation_id);
    }
}
