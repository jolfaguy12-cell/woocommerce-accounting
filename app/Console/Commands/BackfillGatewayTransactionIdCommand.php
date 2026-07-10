<?php

namespace App\Console\Commands;

use App\Domain\Orders\Models\Order;
use Illuminate\Console\Command;

class BackfillGatewayTransactionIdCommand extends Command
{
    protected $signature = 'acc:orders:backfill-gateway-transaction-id';

    protected $description = 'One-off: populate gateway_transaction_id on existing Zibal orders from their already-stored raw payload (the column did not exist when they were first normalized)';

    public function handle(): int
    {
        $orders = Order::whereNull('gateway_transaction_id')
            ->where(function ($q) {
                $q->where('payment_method', 'like', '%zibal%')
                    ->orWhere('payment_method_title', 'like', '%زیبال%');
            })
            ->with('rawOrder')
            ->get();

        $updated = 0;
        foreach ($orders as $order) {
            $transactionId = $order->rawOrder?->payload['transaction_id'] ?? null;
            if ($transactionId) {
                $order->update(['gateway_transaction_id' => (string) $transactionId]);
                $updated++;
            }
        }

        $this->info("Backfilled gateway_transaction_id for {$updated} of {$orders->count()} Zibal orders.");

        return self::SUCCESS;
    }
}
