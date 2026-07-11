<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Models\Party;
use App\Domain\Receivables\Models\CreditOrder;

/**
 * Shared FIFO allocation: a single incoming amount (a payment or a write-off)
 * settles a customer's oldest open balance first, spilling into the
 * next-oldest once the current one is fully covered. Used by both
 * PaymentRecorder::receiveForCustomer() and CreditOrderService::writeOff()
 * so the "oldest debt first" rule lives in exactly one place.
 */
class CreditOrderAllocator
{
    /**
     * @return array{applied: int, lines: array<int, array{credit_order: CreditOrder, amount: int}>}
     */
    public function apply(Party $party, int $amount): array
    {
        $creditOrders = CreditOrder::query()
            ->where('credit_orders.party_id', $party->id)
            ->where('credit_orders.status', 'open')
            ->leftJoin('orders', 'orders.id', '=', 'credit_orders.order_id')
            ->orderByRaw('COALESCE(orders.order_date, credit_orders.created_at) asc')
            ->select('credit_orders.*')
            ->lockForUpdate()
            ->get();

        $remaining = $amount;
        $lines = [];

        foreach ($creditOrders as $creditOrder) {
            if ($remaining <= 0) {
                break;
            }

            $portion = min($remaining, $creditOrder->remaining());

            if ($portion <= 0) {
                continue;
            }

            $creditOrder->update([
                'paid_total' => $creditOrder->paid_total + $portion,
                'status' => $creditOrder->paid_total + $portion >= $creditOrder->total_due ? 'settled' : 'open',
            ]);

            $lines[] = ['credit_order' => $creditOrder, 'amount' => $portion];
            $remaining -= $portion;
        }

        return ['applied' => $amount - $remaining, 'lines' => $lines];
    }
}
