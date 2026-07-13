<?php

namespace App\Domain\Costing\Services;

use App\Domain\Costing\Models\PurchaseInvoice;
use App\Domain\Costing\Models\PurchaseInvoiceLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Overdue" = still not fully received 5 days after invoice_date, or after
 * expected_delivery_date when the supplier gave one. Used by the scheduled
 * detection command, the sidebar badge count, and the supplier/unreceived-
 * goods pages — one definition, everywhere.
 */
class OverdueReceivingService
{
    private const GRACE_DAYS = 5;

    public function overdueInvoicesQuery(): Builder
    {
        $graceCutoff = Carbon::today()->subDays(self::GRACE_DAYS)->toDateString();
        $today = Carbon::today()->toDateString();

        return PurchaseInvoice::query()
            ->whereIn('status', ['draft', 'partial'])
            ->whereHas('lines', fn ($q) => $q->whereColumn('received_qty', '<', 'qty'))
            ->where(function ($q) use ($graceCutoff, $today) {
                $q->where(fn ($qq) => $qq->whereNotNull('expected_delivery_date')->whereDate('expected_delivery_date', '<=', $today))
                    ->orWhere(fn ($qq) => $qq->whereNull('expected_delivery_date')->whereDate('invoice_date', '<=', $graceCutoff));
            });
    }

    /** @return array<int, array{line: PurchaseInvoiceLine, outstanding_qty: int, age_days: int}> */
    public function overdueLinesFor(PurchaseInvoice $invoice): array
    {
        $invoice->loadMissing('lines');
        $dueSince = $invoice->expected_delivery_date ?? $invoice->invoice_date->copy()->addDays(self::GRACE_DAYS);

        return $invoice->lines
            ->filter(fn (PurchaseInvoiceLine $line) => $line->received_qty < $line->qty)
            ->map(fn (PurchaseInvoiceLine $line) => [
                'line' => $line,
                'outstanding_qty' => $line->qty - $line->received_qty,
                'age_days' => max(0, (int) $dueSince->diffInDays(Carbon::today())),
            ])
            ->values()
            ->all();
    }

    /**
     * Flat, sortable/paginatable row-per-outstanding-line query for the
     * "Unreceived Goods" page and the supplier overdue tab (pass
     * $supplierPartyId to scope to one supplier). One row per line rather
     * than per invoice so ordered/received/outstanding/package/notes can
     * each be shown per item; invoice_no groups them visually via sorting.
     */
    public function overdueLineRowsQuery(?int $supplierPartyId = null): Builder
    {
        $graceCutoff = Carbon::today()->subDays(self::GRACE_DAYS)->toDateString();
        $today = Carbon::today()->toDateString();

        return PurchaseInvoiceLine::query()
            ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_lines.purchase_invoice_id')
            ->join('cost_items', 'cost_items.id', '=', 'purchase_invoice_lines.cost_item_id')
            ->join('parties', 'parties.id', '=', 'purchase_invoices.supplier_party_id')
            ->whereIn('purchase_invoices.status', ['draft', 'partial'])
            ->whereColumn('purchase_invoice_lines.received_qty', '<', 'purchase_invoice_lines.qty')
            ->where(function ($q) use ($graceCutoff, $today) {
                $q->where(fn ($qq) => $qq->whereNotNull('purchase_invoices.expected_delivery_date')->whereDate('purchase_invoices.expected_delivery_date', '<=', $today))
                    ->orWhere(fn ($qq) => $qq->whereNull('purchase_invoices.expected_delivery_date')->whereDate('purchase_invoices.invoice_date', '<=', $graceCutoff));
            })
            ->when($supplierPartyId, fn ($q) => $q->where('purchase_invoices.supplier_party_id', $supplierPartyId))
            ->select([
                'purchase_invoice_lines.*',
                'purchase_invoices.invoice_no', 'purchase_invoices.invoice_date', 'purchase_invoices.expected_delivery_date',
                'cost_items.name as item_name',
                'parties.id as supplier_party_id', 'parties.name as supplier_name',
            ])
            ->selectRaw('(purchase_invoice_lines.qty - purchase_invoice_lines.received_qty) as outstanding_qty')
            ->selectRaw($this->ageDaysExpr(), [$today, self::GRACE_DAYS]);
    }

    /**
     * DATEDIFF()/DATE_ADD() are MySQL-only (this app's production driver);
     * the Pest suite runs against in-memory SQLite for speed, so both
     * dialects need their own portable equivalent here.
     */
    private function ageDaysExpr(): string
    {
        $dueDate = DB::connection()->getDriverName() === 'sqlite'
            ? "COALESCE(purchase_invoices.expected_delivery_date, date(purchase_invoices.invoice_date, '+' || ? || ' days'))"
            : 'COALESCE(purchase_invoices.expected_delivery_date, DATE_ADD(purchase_invoices.invoice_date, INTERVAL ? DAY))';

        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(julianday(?) - julianday({$dueDate}) AS INTEGER) as age_days"
            : "DATEDIFF(?, {$dueDate}) as age_days";
    }
}
