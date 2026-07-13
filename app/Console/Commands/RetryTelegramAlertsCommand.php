<?php

namespace App\Console\Commands;

use App\Domain\Alerts\Models\AlertDelivery;
use App\Jobs\SendTelegramAlertJob;
use Illuminate\Console\Command;

class RetryTelegramAlertsCommand extends Command
{
    protected $signature = 'acc:alerts:retry-telegram';

    protected $description = 'Safety net: re-queue any telegram alert deliveries stuck pending/failed (e.g. the queue worker was down, or a transient Telegram API error exhausted the job\'s own retries)';

    public function handle(): int
    {
        $deliveries = AlertDelivery::where('channel', 'telegram')
            ->whereIn('status', ['pending', 'failed'])
            ->get();

        foreach ($deliveries as $delivery) {
            SendTelegramAlertJob::dispatch($delivery->id);
        }

        $this->info("Re-queued {$deliveries->count()} telegram delivery/deliveries.");

        return self::SUCCESS;
    }
}
