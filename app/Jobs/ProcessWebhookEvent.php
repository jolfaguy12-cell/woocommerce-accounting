<?php

namespace App\Jobs;

use App\Domain\Sync\Models\WebhookEvent;
use App\Domain\Sync\Services\WebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5; // processor parks the event as dead before this is exhausted

    public function __construct(public readonly int $webhookEventId) {}

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(WebhookProcessor $processor): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if ($event) {
            $processor->process($event);
        }
    }
}
