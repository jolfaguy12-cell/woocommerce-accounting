<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Models\Setting;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Orders\Models\Order;
use App\Domain\Receivables\Models\CreditOrder;
use App\Domain\Sync\Models\ReviewItem;
use Illuminate\Support\Str;

/**
 * Keeps a CreditOrder in sync with the AR debit ProfitEngine already posts
 * for a real order — the missing link that lets a regular sale (not just a
 * manual "credit sale") be tracked and settled as a receivable. Never
 * touches paid_total; that's exclusively CreditOrderAllocator's job.
 */
class CreditOrderSync
{
    /** Called after every (re)posting attempt, whether or not anything actually changed. */
    public function sync(Order $order, array $calculateResult, ?int $journalEntryId): void
    {
        $totalDue = $calculateResult['net_sale'] + $calculateResult['shipping_charged'];

        if ($totalDue <= 0 || ! $this->eligible($order)) {
            $this->reverse($order);

            return;
        }

        $creditOrder = CreditOrder::firstOrNew(['order_id' => $order->id]);
        $isNew = ! $creditOrder->exists;

        if ($isNew) {
            $creditOrder->uuid = (string) Str::uuid();
            $creditOrder->paid_total = 0;
        }

        $creditOrder->party_id = $order->customer_party_id;
        $creditOrder->total_due = $totalDue;
        $creditOrder->journal_entry_id = $journalEntryId;
        $creditOrder->status = $creditOrder->paid_total >= $totalDue ? 'settled' : 'open';
        $creditOrder->save();

        // A recalculation (e.g. a late discount correction) dropped total_due
        // below what was already recorded as paid — don't silently move the
        // difference to customer credit on the order's behalf; a human needs
        // to decide where that money actually belongs.
        if (! $isNew && $creditOrder->paid_total > $totalDue) {
            $this->openOnce('credit_order_overpaid_after_recalc', $creditOrder, [
                'order_id' => $order->id,
                'total_due' => $totalDue,
                'paid_total' => $creditOrder->paid_total,
            ]);
        }
    }

    /** Called when an order leaves valid/posted state (cancelled, refunded, blocked, or became ineligible). */
    public function reverse(Order $order): void
    {
        $creditOrder = CreditOrder::where('order_id', $order->id)->first();

        if (! $creditOrder) {
            return;
        }

        if ($creditOrder->paid_total === 0) {
            $creditOrder->delete(); // nothing was ever collected — nothing to reconcile

            return;
        }

        // Real money was received against an order that's no longer valid.
        // Never auto-delete or auto-reconcile a receivable with money already
        // in it — pull it out of the open/allocatable pool and flag it.
        if ($creditOrder->status !== 'settled') {
            $creditOrder->update(['status' => 'settled']);
            $this->openOnce('credit_order_reversed_with_payments', $creditOrder, [
                'order_id' => $order->id,
                'paid_total' => $creditOrder->paid_total,
                'total_due' => $creditOrder->total_due,
            ]);
        }
    }

    /**
     * Eligibility (README: prefer configurable mappings, never hard-code a
     * channel list) — a channel that already settles with the store directly
     * (today, only Basalam) is never tracked, nor is an order already
     * confirmed paid by real hub/gateway data, nor anything before the
     * cutover date the user confirmed.
     */
    private function eligible(Order $order): bool
    {
        if ($order->channel?->config['payment_prepaid_by_channel'] ?? false) {
            return false;
        }

        if ($order->payment_status === 'paid') {
            return false;
        }

        $cutover = Setting::get('receivables_cutover_date');
        $orderDate = $order->order_date->copy()->setTimezone(JalaliPeriod::TIMEZONE)->toDateString();

        return ! $cutover || $orderDate >= $cutover;
    }

    private function openOnce(string $type, CreditOrder $creditOrder, array $payload): void
    {
        $exists = ReviewItem::where('type', $type)
            ->where('subject_type', 'credit_order')
            ->where('subject_id', $creditOrder->id)
            ->where('status', 'open')
            ->exists();

        if (! $exists) {
            ReviewItem::open($type, $creditOrder, $payload);
        }
    }
}
