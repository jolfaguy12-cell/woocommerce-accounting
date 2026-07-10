<?php

namespace App\Domain\Costing\Services;

use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Costing\Models\PurchaseInvoice;
use App\Domain\Costing\Models\PurchaseInvoiceLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseInvoiceService
{
    private const INVENTORY_ACCOUNT = '1300';

    private const PAYABLES_ACCOUNT = '2000';

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly ProductMappingResolver $mappingResolver,
    ) {}

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
                    'product_mirror_id' => $line['product_mirror_id'] ?? null,
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
     * Edit an existing invoice: header fields, per-line unit_price/note, and
     * new lines can be added. Line qty/removal on already-received lines is
     * intentionally out of scope (would need partial-reversal accounting not
     * built yet) — only unit_price and shipping_cost changes are supported
     * once a line has been received.
     *
     * Shipping is always reallocated across every line by qty (or manual)
     * after any edit, since shipping is often only known a day or two after
     * the goods went out. If a line was already received, its corrected
     * landed cost is written as a NEW cost_history row (never mutating the
     * old one — see JournalPoster's reversal-only rule). If the invoice was
     * already fully received (journal posted), the old journal entry is
     * reversed and a corrected one is posted with the new total — never a
     * silent edit of a posted entry.
     */
    public function update(PurchaseInvoice $invoice, array $data, ?int $by = null): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $data, $by) {
            $wasJournaled = $invoice->journal_entry_id !== null;

            $invoice->update(array_filter([
                'invoice_no' => $data['invoice_no'] ?? $invoice->invoice_no,
                'invoice_date' => isset($data['invoice_date'])
                    ? Carbon::parse($data['invoice_date'], JalaliPeriod::TIMEZONE)->toDateString()
                    : $invoice->invoice_date,
                'jalali_period' => isset($data['invoice_date'])
                    ? JalaliPeriod::fromDate(Carbon::parse($data['invoice_date'], JalaliPeriod::TIMEZONE))
                    : $invoice->jalali_period,
                'shipping_cost' => isset($data['shipping_cost']) ? (int) $data['shipping_cost'] : $invoice->shipping_cost,
            ]));

            foreach ($data['lines'] ?? [] as $lineData) {
                if (! empty($lineData['id'])) {
                    $line = $invoice->lines->firstWhere('id', $lineData['id']);
                    $line?->update(array_filter([
                        'unit_price' => $lineData['unit_price'] ?? null,
                        'note' => $lineData['note'] ?? $line->note,
                    ], fn ($v) => $v !== null));
                } else {
                    $invoice->lines()->create([
                        'cost_item_id' => $lineData['cost_item_id'],
                        'product_mirror_id' => $lineData['product_mirror_id'] ?? null,
                        'qty' => $lineData['qty'],
                        'unit_price' => $lineData['unit_price'],
                        'shipping_allocated' => 0,
                        'landed_unit_cost' => $lineData['unit_price'],
                        'note' => $lineData['note'] ?? null,
                    ]);
                }
            }

            $invoice->refresh();
            $lines = $invoice->lines;
            $totalQty = max(1, $lines->sum('qty'));

            foreach ($lines as $line) {
                $allocated = $invoice->shipping_allocation === 'manual'
                    ? $line->shipping_allocated
                    : (int) round($invoice->shipping_cost * $line->qty / $totalQty);
                $newLanded = (int) round($line->unit_price + $allocated / max(1, $line->qty));

                if ($allocated === $line->shipping_allocated && $newLanded === $line->landed_unit_cost) {
                    continue;
                }

                $line->update(['shipping_allocated' => $allocated, 'landed_unit_cost' => $newLanded]);

                if ($line->received_qty > 0) {
                    $line->costItem->costHistory()->create([
                        'unit_cost' => $line->unit_price,
                        'landed_unit_cost' => $newLanded,
                        'source' => 'invoice',
                        'source_id' => $line->id,
                        'effective_at' => $invoice->invoice_date,
                        'created_by' => $by,
                    ]);
                    $this->cascadeToVariations($line, $newLanded, $by);
                }
            }

            if ($wasJournaled) {
                $invoice->refresh();
                $this->poster->reverse($invoice->journalEntry, "اصلاح فاکتور خرید #{$invoice->id}", $by);
                $invoice->update(['journal_entry_id' => null]);
                $this->postJournal($invoice, $by, correction: true);
            }

            return $invoice->refresh()->load('lines');
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
                    $this->cascadeToVariations($line, $line->landed_unit_cost, $by);
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

    /**
     * If this line was purchased against a variable (parent) product, apply
     * the same landed cost to every one of its variations too — mirroring
     * how a variable product's wholesale price cascades to its variations.
     * This only writes cost_history (profit-discovery data) for each
     * variation's own Cost Item; it never touches this invoice's totals or
     * journal, since no real separate quantity was purchased for them.
     */
    private function cascadeToVariations(PurchaseInvoiceLine $line, int $landedUnitCost, ?int $by): void
    {
        $product = $line->product;

        if (! $product || $product->type !== 'variable') {
            return;
        }

        foreach ($product->variations as $variation) {
            $mapping = $this->mappingResolver->resolveOrCreate($variation);

            $mapping->costItem->costHistory()->create([
                'unit_cost' => $line->unit_price,
                'landed_unit_cost' => $landedUnitCost,
                'source' => 'invoice',
                'source_id' => $line->id,
                'effective_at' => $line->invoice->invoice_date,
                'created_by' => $by,
            ]);
        }
    }

    private function postJournal(PurchaseInvoice $invoice, ?int $by, bool $correction = false): void
    {
        $total = $invoice->lines->sum(fn ($l) => $l->qty * $l->unit_price) + $invoice->shipping_cost;

        $entry = $this->poster->post([
            'entry_date' => Carbon::parse($invoice->invoice_date, JalaliPeriod::TIMEZONE),
            'description' => ($correction ? 'اصلاح ' : '')."فاکتور خرید {$invoice->invoice_no} — {$invoice->supplier->name}",
            // A correction always needs a fresh key: the original "purchase:{uuid}"
            // key is now attached to a reversed entry, and re-using it would just
            // hand back that reversed entry instead of posting the new total.
            'idempotency_key' => $correction ? 'purchase-correction:'.Str::uuid() : "purchase:{$invoice->uuid}",
            'source' => $invoice,
            'created_by' => $by,
        ], [
            ['account' => self::INVENTORY_ACCOUNT, 'debit' => $total],
            ['account' => self::PAYABLES_ACCOUNT, 'credit' => $total, 'party_id' => $invoice->supplier_party_id],
        ]);

        $invoice->update(['journal_entry_id' => $entry->id]);
    }
}
