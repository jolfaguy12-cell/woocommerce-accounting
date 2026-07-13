<?php

namespace App\Jobs;

use App\Domain\Alerts\Models\AlertDelivery;
use App\Domain\Alerts\Services\TelegramNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendTelegramAlertJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $deliveryId) {}

    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function handle(TelegramNotifier $notifier): void
    {
        $delivery = AlertDelivery::find($this->deliveryId);

        // Already sent (e.g. picked up again by the retry safety-net) or no longer relevant.
        if (! $delivery || $delivery->status === 'sent') {
            return;
        }

        $notifier->send($delivery);
    }

    /** Retries exhausted — the delivery row's own status/error (set by TelegramNotifier) is the durable record. */
    public function failed(Throwable $e): void
    {
        AlertDelivery::where('id', $this->deliveryId)->where('status', '!=', 'sent')
            ->update(['status' => 'failed', 'error' => $e->getMessage()]);
    }
}
