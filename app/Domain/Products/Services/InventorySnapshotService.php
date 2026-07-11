<?php

namespace App\Domain\Products\Services;

use App\Domain\Products\Models\InventorySnapshot;
use App\Domain\Products\Models\ProductMirror;

/**
 * Exact on-hand stock (units) and its value at our current selling price
 * (not purchase/landed cost) across every sellable simple/variation item.
 * Scanning all products is too expensive to redo on every dashboard
 * refresh, so this is only ever computed by acc:products:snapshot-inventory
 * (scheduled every few hours — see routes/console.php) and read back via
 * latest(); every run is kept as its own row so reports can later chart
 * inventory value/units over time without any new backend work.
 */
class InventorySnapshotService
{
    public function refresh(): InventorySnapshot
    {
        $row = ProductMirror::whereIn('type', ['simple', 'variation'])
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'trash'))
            ->whereNotNull('stock_quantity')
            ->selectRaw('COALESCE(SUM(stock_quantity), 0) as total_units')
            ->selectRaw('COALESCE(SUM(stock_quantity * COALESCE(price, regular_price, 0)), 0) as total_value')
            ->first();

        return InventorySnapshot::create([
            'total_units' => (int) $row->total_units,
            'total_value' => (int) $row->total_value,
            'computed_at' => now(),
        ]);
    }

    public function latest(): ?InventorySnapshot
    {
        return InventorySnapshot::latest('computed_at')->first();
    }
}
