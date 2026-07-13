<?php

namespace App\Console\Commands;

use App\Domain\Alerts\Services\AlertDispatcher;
use App\Domain\Costing\Services\OverdueReceivingService;
use App\Domain\Sync\Models\ReviewItem;
use Illuminate\Console\Command;

class DetectOverduePurchaseReceipts extends Command
{
    protected $signature = 'acc:purchases:detect-overdue-receipts';

    protected $description = 'Flag purchase invoices still not fully received 5 days after invoice_date (or after expected_delivery_date) — opens a ReviewItem and alerts admin/accountant/warehouse once per invoice, never duplicating while it stays open';

    public function handle(OverdueReceivingService $overdue, AlertDispatcher $alerts): int
    {
        $invoices = $overdue->overdueInvoicesQuery()->with('supplier', 'lines')->get();

        $flagged = 0;

        foreach ($invoices as $invoice) {
            $alreadyOpen = ReviewItem::where('type', 'purchase_receipt_overdue')
                ->where('subject_type', 'purchase_invoice')
                ->where('subject_id', $invoice->id)
                ->where('status', 'open')
                ->exists();

            if ($alreadyOpen) {
                continue;
            }

            $lines = $overdue->overdueLinesFor($invoice);
            $outstandingQty = array_sum(array_column($lines, 'outstanding_qty'));
            $daysOverdue = $lines === [] ? 0 : max(array_column($lines, 'age_days'));

            ReviewItem::open('purchase_receipt_overdue', $invoice, [
                'outstanding_qty' => $outstandingQty,
                'days_overdue' => $daysOverdue,
            ]);

            $alerts->dispatch('purchase_receipt_overdue', [
                'invoice_no' => $invoice->invoice_no ?? "#{$invoice->id}",
                'supplier_name' => $invoice->supplier->name,
                'outstanding_qty' => $outstandingQty,
                'days_overdue' => $daysOverdue,
            ], $invoice, route('purchases.show', $invoice));

            $flagged++;
        }

        $this->info("Checked {$invoices->count()} overdue invoice(s), newly flagged {$flagged}.");

        return self::SUCCESS;
    }
}
