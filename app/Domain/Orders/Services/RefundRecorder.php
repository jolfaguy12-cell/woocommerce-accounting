<?php

namespace App\Domain\Orders\Services;

use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderProfit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Explicit (partial) refunds as proportional reversal entries — history stays intact. */
class RefundRecorder
{
    public function __construct(private readonly JournalPoster $poster) {}

    public function record(Order $order, int $amount, string $reason, ?int $by = null): void
    {
        $profit = OrderProfit::firstWhere('order_id', $order->id);

        if (! $profit || ! $profit->journal_entry_id) {
            throw new InvalidArgumentException('Refunds require a posted profit entry.');
        }
        if ($amount <= 0 || $amount > $profit->net_sale) {
            throw new InvalidArgumentException('Refund amount must be within the order net sale.');
        }

        DB::transaction(function () use ($order, $profit, $amount, $reason, $by) {
            $refund = $order->refunds()->create([
                'amount' => $amount,
                'reason' => $reason,
                'created_by' => $by,
            ]);

            $ratio = $amount / $profit->net_sale;
            $cogsShare = (int) round(($profit->product_cost ?? 0) * $ratio);

            $lines = [
                ['account' => '4000', 'debit' => $amount],
                ['account' => '1200', 'credit' => $amount, 'party_id' => $order->customer_party_id],
            ];
            if ($cogsShare > 0) {
                $lines[] = ['account' => '1300', 'debit' => $cogsShare];
                $lines[] = ['account' => '5000', 'credit' => $cogsShare];
            }

            $entry = $this->poster->post([
                'entry_date' => Carbon::now(JalaliPeriod::TIMEZONE),
                'description' => "برگشت از فروش سفارش {$order->hub_order_id}: {$reason}",
                'idempotency_key' => "order:{$order->hub_order_id}:refund:{$refund->id}",
                'source' => $order,
                'created_by' => $by,
            ], $lines);

            $refund->update(['journal_entry_id' => $entry->id]);

            $totalRefunded = $order->refunds()->sum('amount');
            $order->update([
                'financial_state' => $totalRefunded >= $profit->net_sale ? 'refunded' : 'partially_refunded',
            ]);
        });
    }
}
