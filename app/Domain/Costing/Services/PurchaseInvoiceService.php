<?php

namespace App\Domain\Costing\Services;

use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Costing\Models\PurchaseInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseInvoiceService
{
    private const INVENTORY_ACCOUNT = '1300';

    private const PAYABLES_ACCOUNT = '2000';

    public function __construct(private readonly JournalPoster $poster) {}

    /** Create a draft invoice; shipping is allocated to lines immediately (by qty unless manual). */
    public function create(array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($data) {
            $date = $data['invoice_date'] instanceof Carbon
                ? $data['invoice_date']
                : Carbon::parse($data['invoice_date'], JalaliPeriod::TIMEZONE);

            $invoice = PurchaseInvoice::create([
                'uuid' => (string) Str::uuid(),
                'supplier_party_id' => $data['supplier_party_id'],
                'invoice_no' => $data['invoice_no'] ?? null,
                'invoice_date' => $date->toDateString(),
                'jalali_period' => JalaliPeriod::fromDate($date),
                'shipping_cost' => (int) ($data['shipping_cost'] ?? 0),
                'shipping_allocation' => $data['shipping_allocation'] ?? 'by_qty',
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            $lines = collect($data['lines']);
            $totalQty = max(1, $lines->sum('qty'));

            foreach ($lines as $line) {
                $allocated = $invoice->shipping_allocation === 'manual'
                    ? (int) ($line['shipping_allocated'] ?? 0)
                    : (int) round($invoice->shipping_cost * $line['qty'] / $totalQty);

                $invoice->lines()->create([
                    'cost_item_id' => $line['cost_item_id'],
                    'qty' => $line['qty'],
                    'unit_price' => $line['unit_price'],
                    'shipping_allocated' => $allocated,
                    'landed_unit_cost' => (int) round($line['unit_price'] + $allocated / max(1, $line['qty'])),
                    'note' => $line['note'] ?? null,
                ]);
            }

            return $invoice->load('lines');
        });
    }

    /**
     * Register received quantities. Writes cost history for lines receiving
     * stock for the first time and posts the journal once fully priced.
     * Idempotent: re-receiving never duplicates history or journal entries.
     */
    public function receive(PurchaseInvoice $invoice, array $receivedByLineId, ?int $by = null): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $receivedByLineId, $by) {
            foreach ($invoice->lines as $line) {
                $received = min((int) ($receivedByLineId[$line->id] ?? $line->received_qty), $line->qty);

                if ($received > 0 && $line->received_qty === 0) {
                    // First receipt fixes the landed cost as the item's latest purchase cost.
                    $line->costItem->costHistory()->create([
                        'unit_cost' => $line->unit_price,
                        'landed_unit_cost' => $line->landed_unit_cost,
                        'source' => 'invoice',
                        'source_id' => $line->id,
                        'effective_at' => $invoice->invoice_date,
                        'created_by' => $by,
                    ]);
                }

                $line->update(['received_qty' => $received]);
            }

            $invoice->refresh();
            $fullyReceived = $invoice->lines->every(fn ($l) => $l->received_qty >= $l->qty);
            $anythingReceived = $invoice->lines->contains(fn ($l) => $l->received_qty > 0);

            $invoice->update(['status' => $fullyReceived ? 'received' : ($anythingReceived ? 'partial' : 'draft')]);

            if ($fullyReceived && ! $invoice->journal_entry_id) {
                $this->postJournal($invoice, $by);
            }

            return $invoice->refresh()->load('lines');
        });
    }

    private function postJournal(PurchaseInvoice $invoice, ?int $by): void
    {
        $total = $invoice->lines->sum(fn ($l) => $l->qty * $l->unit_price) + $invoice->shipping_cost;

        $entry = $this->poster->post([
            'entry_date' => Carbon::parse($invoice->invoice_date, JalaliPeriod::TIMEZONE),
            'description' => "فاکتور خرید {$invoice->invoice_no} — {$invoice->supplier->name}",
            'idempotency_key' => "purchase:{$invoice->uuid}",
            'source' => $invoice,
            'created_by' => $by,
        ], [
            ['account' => self::INVENTORY_ACCOUNT, 'debit' => $total],
            ['account' => self::PAYABLES_ACCOUNT, 'credit' => $total, 'party_id' => $invoice->supplier_party_id],
        ]);

        $invoice->update(['journal_entry_id' => $entry->id]);
    }
}
