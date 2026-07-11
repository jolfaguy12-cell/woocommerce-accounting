<?php

namespace App\Console\Commands;

use App\Domain\Products\Services\InventorySnapshotService;
use Illuminate\Console\Command;

class SnapshotInventoryCommand extends Command
{
    protected $signature = 'acc:products:snapshot-inventory
        {--json : Machine-readable output}';

    protected $description = 'Compute exact on-hand stock units and their value at our current selling price across every sellable product/variation, and store it as a new inventory_snapshots row (see InventorySnapshotService).';

    public function handle(InventorySnapshotService $service): int
    {
        $snapshot = $service->refresh();

        $this->option('json')
            ? $this->line(json_encode([
                'total_units' => $snapshot->total_units,
                'total_value' => $snapshot->total_value,
                'computed_at' => $snapshot->computed_at->toIso8601String(),
            ], JSON_UNESCAPED_SLASHES))
            : $this->info("Inventory snapshot: {$snapshot->total_units} units worth ".number_format($snapshot->total_value).' Toman.');

        return self::SUCCESS;
    }
}
