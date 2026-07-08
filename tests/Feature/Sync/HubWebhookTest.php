<?php

use App\Domain\Sync\Models\RawOrder;
use App\Domain\Sync\Models\WebhookEvent;
use Illuminate\Support\Facades\Queue;

function signedPayload(array $payload, ?string $secret = null): array
{
    $body = json_encode($payload);
    $signature = hash_hmac('sha256', $body, $secret ?? config('hub.webhook_secret'));

    return [$body, $signature];
}

beforeEach(function () {
    config(['hub.webhook_secret' => 'test-secret']);
    Queue::fake();
});

it('rejects webhooks with an invalid signature', function () {
    [$body] = signedPayload(['event_id' => 'evt-1', 'order' => ['id' => 10]]);

    $this->call('POST', '/webhooks/hub', [], [], [], [
        'HTTP_X-BDSK-Signature' => 'deadbeef',
        'HTTP_X-BDSK-Event' => 'order.upserted',
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertUnauthorized();

    expect(WebhookEvent::count())->toBe(0);
});

it('accepts a valid webhook, stores the event, and dedups replays', function () {
    [$body, $sig] = signedPayload(['event_id' => 'evt-2', 'order' => ['id' => 11]]);
    $headers = [
        'HTTP_X-BDSK-Signature' => $sig,
        'HTTP_X-BDSK-Event' => 'order.upserted',
        'CONTENT_TYPE' => 'application/json',
    ];

    $this->call('POST', '/webhooks/hub', [], [], [], $headers, $body)->assertOk();
    $this->call('POST', '/webhooks/hub', [], [], [], $headers, $body)->assertOk(); // replay

    expect(WebhookEvent::count())->toBe(1)
        ->and(WebhookEvent::first()->event_type)->toBe('order.upserted')
        ->and(WebhookEvent::first()->status)->toBe('received');
});

it('accepts sha256= prefixed signatures', function () {
    [$body, $sig] = signedPayload(['event_id' => 'evt-3', 'order' => ['id' => 12]]);

    $this->call('POST', '/webhooks/hub', [], [], [], [
        'HTTP_X-BDSK-Signature' => "sha256={$sig}",
        'HTTP_X-BDSK-Event' => 'order.upserted',
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    expect(WebhookEvent::count())->toBe(1);
});

it('never creates raw orders from a webhook before processing', function () {
    [$body, $sig] = signedPayload(['event_id' => 'evt-4', 'order' => ['id' => 13]]);

    $this->call('POST', '/webhooks/hub', [], [], [], [
        'HTTP_X-BDSK-Signature' => $sig,
        'HTTP_X-BDSK-Event' => 'order.upserted',
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    expect(RawOrder::count())->toBe(0); // processing happens on the queue
});
