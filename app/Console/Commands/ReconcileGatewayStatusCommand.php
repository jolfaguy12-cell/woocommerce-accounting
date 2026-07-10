<?php

namespace App\Console\Commands;

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\GatewayReconciliationService;
use Illuminate\Console\Command;

class ReconcileGatewayStatusCommand extends Command
{
    protected $signature = 'acc:orders:reconcile-gateway
        {--limit=200 : Max orders to check in one run}';

    protected $description = 'Check Zibal-paid orders that are financially valid against Zibal\'s own inquiry API, flagging mismatches for review';

    /**
     * NOT scheduled yet — deliberately. Validated against 5 real, recent
     * Zibal orders (including one from the same day) and every single
     * inquiry call returned {"message":"invalid trackId","result":203}.
     * The `transaction_id` WooCommerce stores for Zibal orders is not
     * Zibal's real trackingCode (or this merchant key can't see it), so
     * right now this command only ever produces lookup_failed and would
     * never actually catch a mismatch. Needs one of: a different/correct
     * Zibal merchant credential, or capturing the real trackingCode at
     * checkout time (check the WooCommerce Zibal plugin's own order meta
     * keys — `transaction_id` isn't it). Do not add to routes/console.php
     * until this is resolved and re-validated.
     */
    public function handle(GatewayReconciliationService $service): int
    {
        $orders = Order::where('financial_state', 'valid')
            ->whereNotNull('gateway_transaction_id')
            ->whereDoesntHave('gatewayChecks', fn ($q) => $q->where('checked_at', '>=', now()->subDay()))
            ->limit((int) $this->option('limit'))
            ->get();

        $mismatches = 0;
        foreach ($orders as $order) {
            $check = $service->checkOrder($order);
            if ($check->mismatch) {
                $mismatches++;
            }
        }

        $this->info("Checked {$orders->count()} orders, found {$mismatches} mismatch(es).");

        return self::SUCCESS;
    }
}
