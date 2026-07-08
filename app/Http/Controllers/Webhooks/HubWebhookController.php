<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Sync\Models\WebhookEvent;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HubWebhookController extends Controller
{
    /** Fast lane: verify, persist, queue. All real work happens on the queue. */
    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->signatureIsValid($request)) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        $payload = $request->json()->all();
        $eventType = $request->header('X-BDSK-Event', 'unknown');
        $eventUuid = $payload['event_id']
            ?? $payload['delivery_id']
            ?? hash('sha256', $eventType.$request->getContent());

        $event = WebhookEvent::firstOrCreate(
            ['event_uuid' => (string) $eventUuid],
            [
                'event_type' => $eventType,
                'payload' => $payload,
                'status' => 'received',
                'correlation_id' => (string) Str::uuid(),
            ],
        );

        if ($event->wasRecentlyCreated) {
            ProcessWebhookEvent::dispatch($event->id);
        }

        return response()->json(['received' => true]);
    }

    private function signatureIsValid(Request $request): bool
    {
        $secret = (string) config('hub.webhook_secret');
        $signature = (string) $request->header('X-BDSK-Signature', '');

        if ($secret === '' || $signature === '') {
            return false;
        }

        $signature = Str::after($signature, 'sha256=');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
