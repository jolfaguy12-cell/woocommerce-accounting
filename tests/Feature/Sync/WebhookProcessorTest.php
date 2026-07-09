<?php

use App\Domain\Sync\Models\RawOrder;
use App\Domain\Sync\Models\ReviewItem;
use App\Domain\Sync\Models\WebhookEvent;
use App\Domain\Sync\Services\WebhookProcessor;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'hub.base_url' => 'https://hub.test/api/v1',
        'hub.api_key' => 'k',
        'hub.webhook_max_attempts' => 3,
    ]);
});

function makeEvent(array $payload, string $type = 'order.upserted'): WebhookEvent
{
    return WebhookEvent::create([
        'event_uuid' => $payload['event_id'] ?? uniqid(),
        'event_type' => $type,
        'payload' => $payload,
        'status' => 'received',
    ]);
}

it('stores a raw order from an order.upserted event with an embedded payload', function () {
    $event = makeEvent(['event_id' => 'e1', 'order' => ['id' => 501, 'status' => 'completed', 'total' => '250000']]);

    app(WebhookProcessor::class)->process($event);

    expect($event->refresh()->status)->toBe('done')
        ->and(RawOrder::count())->toBe(1)
        ->and(RawOrder::first()->hub_order_id)->toBe(501)
        ->and(RawOrder::first()->fetched_via)->toBe('webhook');
});

it('fetches the full order from the hub when the payload only carries an id', function () {
    Http::fake([
        'hub.test/api/v1/orders/502' => Http::response(['id' => 502, 'status' => 'processing', 'total' => '99000']),
    ]);

    $event = makeEvent(['event_id' => 'e2', 'order_id' => 502]);
    app(WebhookProcessor::class)->process($event);

    expect(RawOrder::first()->hub_order_id)->toBe(502)
        ->and(RawOrder::first()->payload['status'])->toBe('processing');
});

it('processing the same order payload twice keeps a single raw order', function () {
    $payload = ['order' => ['id' => 503, 'status' => 'completed']];

    app(WebhookProcessor::class)->process(makeEvent($payload + ['event_id' => 'e3']));
    app(WebhookProcessor::class)->process(makeEvent($payload + ['event_id' => 'e4']));

    expect(RawOrder::count())->toBe(1);
});

it('updates the raw order when the payload changes but ignores stale updates', function () {
    $t1 = ['order' => ['id' => 504, 'status' => 'processing', 'date_updated_gmt' => '2026-07-08 10:00:00']];
    $t2 = ['order' => ['id' => 504, 'status' => 'completed', 'date_updated_gmt' => '2026-07-08 11:00:00']];

    app(WebhookProcessor::class)->process(makeEvent($t1 + ['event_id' => 'e5']));
    app(WebhookProcessor::class)->process(makeEvent($t2 + ['event_id' => 'e6']));
    app(WebhookProcessor::class)->process(makeEvent($t1 + ['event_id' => 'e7'])); // stale replay

    expect(RawOrder::count())->toBe(1)
        ->and(RawOrder::first()->payload['status'])->toBe('completed');
});

it('reads the hub envelope: order.upserted with a full data snapshot needs no API fetch', function () {
    Http::fake(); // any HTTP call would fail the test below

    $event = makeEvent([
        'event_id' => 'e9',
        'event' => 'order.upserted',
        'entity_type' => 'order',
        'entity_id' => 6599,
        'timestamp' => '2026-07-08T23:58:06+00:00',
        'data' => ['id' => 6599, 'status' => 'pending', 'total' => '996500.00000000', 'currency' => 'IRT'],
    ]);

    app(WebhookProcessor::class)->process($event);

    Http::assertNothingSent();
    expect($event->refresh()->status)->toBe('done')
        ->and(RawOrder::first()->hub_order_id)->toBe(6599)
        ->and(RawOrder::first()->payload['status'])->toBe('pending');
});

it('reads the hub envelope: a thin data payload (id only) triggers a full API fetch', function () {
    Http::fake([
        'hub.test/api/v1/orders/6600' => Http::response(['id' => 6600, 'status' => 'completed', 'total' => '50000']),
    ]);

    $event = makeEvent([
        'event_id' => 'e10',
        'entity_type' => 'order',
        'entity_id' => 6600,
        'data' => ['id' => 6600],
    ]);

    app(WebhookProcessor::class)->process($event);

    expect(RawOrder::first()->hub_order_id)->toBe(6600)
        ->and(RawOrder::first()->payload['status'])->toBe('completed');
});

it('reads the hub envelope for product events via entity_id', function () {
    Http::fake([
        'hub.test/api/v1/products/700' => Http::response(['id' => 700, 'name' => 'p', 'type' => 'simple', 'sku' => 'p-700', 'price' => '1000']),
        'hub.test/api/v1/products/700/variations' => Http::response([]),
    ]);

    $event = makeEvent([
        'event_id' => 'e11',
        'entity_type' => 'product',
        'entity_id' => 700,
        'data' => ['id' => 700],
    ], 'product.upserted');

    app(WebhookProcessor::class)->process($event);

    expect($event->refresh()->status)->toBe('done');
});

it('marks the event dead after max attempts and opens a sync-error review item', function () {
    $event = makeEvent(['event_id' => 'e8'], 'order.upserted'); // no order id → always fails

    $processor = app(WebhookProcessor::class);
    foreach (range(1, 3) as $i) {
        try {
            $processor->process($event->refresh());
        } catch (Throwable) {
            // queue would retry
        }
    }

    expect($event->refresh()->status)->toBe('dead')
        ->and($event->attempts)->toBe(3)
        ->and(ReviewItem::where('type', 'sync_error')->count())->toBe(1);
});
