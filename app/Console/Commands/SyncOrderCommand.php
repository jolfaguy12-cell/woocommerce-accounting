<?php

namespace App\Console\Commands;

use App\Domain\Sync\Services\HubClient;
use App\Domain\Sync\Services\RawOrderUpserter;
use Illuminate\Console\Command;
use Throwable;

class SyncOrderCommand extends Command
{
    protected $signature = 'acc:sync:order {hub_order_id} {--json : Machine-readable output}';

    protected $description = 'Fetch one order from the hub and store/refresh its raw payload';

    public function handle(HubClient $hub, RawOrderUpserter $orders): int
    {
        $id = (int) $this->argument('hub_order_id');

        try {
            $raw = $orders->upsert($id, $hub->order($id), 'manual');
        } catch (Throwable $e) {
            $this->error("Failed to sync order {$id}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $result = [
            'hub_order_id' => $raw->hub_order_id,
            'status' => $raw->payload['status'] ?? null,
            'received_at' => $raw->received_at->toIso8601String(),
        ];

        $this->option('json')
            ? $this->line(json_encode($result, JSON_UNESCAPED_SLASHES))
            : $this->info("Order {$raw->hub_order_id} stored (status: {$result['status']}).");

        return self::SUCCESS;
    }
}
