<?php

namespace App\Console\Commands;

use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Sync\Services\HubClient;
use Illuminate\Console\Command;
use Throwable;

class SyncOrderCommand extends Command
{
    protected $signature = 'acc:sync:order {hub_order_id} {--json : Machine-readable output}';

    protected $description = 'Fetch one order from the hub, store its raw payload, and normalize it';

    public function handle(HubClient $hub, OrderIngestPipeline $pipeline): int
    {
        $id = (int) $this->argument('hub_order_id');

        try {
            $order = $pipeline->ingest($id, $hub->order($id), 'manual');
        } catch (Throwable $e) {
            $this->error("Failed to sync order {$id}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $result = [
            'hub_order_id' => $order->hub_order_id,
            'status' => $order->status,
            'total' => $order->total,
            'jalali_period' => $order->jalali_period,
            'channel' => $order->channel?->slug,
            'raw_source' => $order->raw_source_value,
            'profit_status' => $order->profit_status,
        ];

        $this->option('json')
            ? $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            : $this->info("Order {$order->hub_order_id} normalized (status: {$order->status}, channel: ".($result['channel'] ?? 'unknown').').');

        return self::SUCCESS;
    }
}
