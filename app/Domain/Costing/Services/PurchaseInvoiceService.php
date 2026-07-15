<?php

namespace App\Domain\Costing\Services;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Alerts\Services\AlertDispatcher;
use App\Domain\Costing\Models\PurchaseInvoice;
use App\Domain\Costing\Models\PurchaseInvoiceLine;
use App\Domain\Costing\Models\PurchaseInvoiceReceipt;
use App\Domain\Costing\Models\PurchaseInvoiceReceiptLine;
use App\Domain\Sync\Models\ReviewItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PurchaseInvoiceService
{
    private const INVENTORY_ACCOUNT = '1300';

    private const PAYABLES_ACCOUNT = '2000';

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly ProductMappingResolver $mappingResolver,
        private readonly AlertDispatcher $alerts,
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
                // Rows the operator typed on a draft: kept unposted here so the
                // edit form can restore them; finalize() posts and clears them.
                'pending_payments' => ! empty($data['pending_payments']) ? $data['pending_payments'] : null,
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
     * Edit an existing invoice: header fields, per-line unit_price/qty/note,
     * adding new lines, and removing lines can all change. A line with
     * received_qty > 0 already has its landed cost baked into cost_history
     * (and possibly a posted journal entry): its qty can only be increased
     * (or left alone), never reduced below received_qty, and it can never be
     * removed. A line with received_qty == 0
     * (including on a partially-received invoice) can be freely qty-edited
     * or removed.
     *
     * Shipping is always reallocated across every remaining line by qty (or
     * manual) after any edit, since shipping is often only known a day or two
     * after the goods went out. If a line was already received, its corrected
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
                'notes' => $data['notes'] ?? $invoice->notes,
            ]));

            // Kept out of the array_filter() above on purpose — an empty array
            // (every payment row removed) is a real, meaningful value here, and
            // array_filter() would silently drop it back to the old rows.
            if (array_key_exists('pending_payments', $data)) {
                $invoice->update(['pending_payments' => $data['pending_payments'] ?: null]);
            }

            $remaining = $invoice->lines->count();

            foreach ($data['lines'] ?? [] as $lineData) {
                if (! empty($lineData['id'])) {
                    $line = $invoice->lines->firstWhere('id', $lineData['id']);

                    if (! $line) {
                        continue;
                    }

                    if (! empty($lineData['_remove'])) {
                        if ($line->received_qty > 0) {
                            throw new InvalidArgumentException("ردیف «{$line->costItem->name}» قبلاً دریافت شده و قابل حذف نیست.");
                        }
                        $line->delete();
                        $remaining--;

                        continue;
                    }

                    if (isset($lineData['qty']) && (int) $lineData['qty'] < $line->received_qty) {
                        throw new InvalidArgumentException("تعداد ردیف «{$line->costItem->name}» را نمی‌توان کمتر از مقدار دریافت‌شده ({$line->received_qty}) کرد.");
                    }

                    $line->update(array_filter([
                        'qty' => $lineData['qty'] ?? null,
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
                    $remaining++;
                }
            }

            if ($remaining < 1) {
                throw new InvalidArgumentException('فاکتور خرید باید حداقل یک ردیف داشته باشد.');
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
     * Register received quantities as an absolute per-line target (used by
     * finalize()'s "receive everything remaining right now" shortcut).
     * Idempotent: re-receiving never duplicates history or journal entries.
     */
    public function receive(PurchaseInvoice $invoice, array $receivedByLineId, ?int $by = null): PurchaseInvoice
    {
        return DB::transaction(fn () => $this->applyReceivedQuantities($invoice, $receivedByLineId, $by));
    }

    /**
     * Record one granular receiving EVENT (partial delivery): incremental
     * qty per line (added to whatever was already received), plus the
     * event's date/notes and an optional package count/label per line —
     * the full audit trail a single absolute-qty receive() call can't carry.
     * Delegates to the same applyReceivedQuantities() so cost_history,
     * variation cascade, status transitions, and journal-once-fully-received
     * behave identically to the existing shortcut.
     *
     * $lineQuantities: [line_id => ['qty' => int, 'package_count' => ?int, 'package_label' => ?string], ...]
     * $meta: ['received_at' => date string, 'notes' => ?string]
     */
    public function recordReceipt(PurchaseInvoice $invoice, array $lineQuantities, array $meta, ?int $by = null): PurchaseInvoiceReceipt
    {
        return DB::transaction(function () use ($invoice, $lineQuantities, $meta, $by) {
            $receipt = PurchaseInvoiceReceipt::create([
                'uuid' => (string) Str::uuid(),
                'purchase_invoice_id' => $invoice->id,
                'received_at' => $meta['received_at'] ?? now()->toDateString(),
                'notes' => $meta['notes'] ?? null,
                'created_by' => $by,
            ]);

            $absoluteByLineId = [];
            $anyReceived = false;

            foreach ($lineQuantities as $lineId => $lineMeta) {
                $qty = (int) ($lineMeta['qty'] ?? 0);
                $line = $invoice->lines->firstWhere('id', $lineId);

                if ($qty <= 0 || ! $line) {
                    continue;
                }

                // Never let a receipt push a line's total past what was ordered.
                $qty = min($qty, $line->qty - $line->received_qty);
                if ($qty <= 0) {
                    continue;
                }

                $receipt->lines()->create([
                    'purchase_invoice_line_id' => $line->id,
                    'qty' => $qty,
                    'package_count' => $lineMeta['package_count'] ?? null,
                    'package_label' => $lineMeta['package_label'] ?? null,
                ]);

                $absoluteByLineId[$line->id] = $line->received_qty + $qty;
                $anyReceived = true;
            }

            if (! $anyReceived) {
                throw new InvalidArgumentException('حداقل یک قلم با تعداد معتبر برای ثبت دریافت لازم است.');
            }

            $this->applyReceivedQuantities($invoice, $absoluteByLineId, $by);

            return $receipt->load('lines.invoiceLine.costItem');
        });
    }

    /**
     * Shared core of receive()/recordReceipt(): given an absolute target
     * received_qty per line, writes cost history on first-received crossing,
     * cascades to variations, and hands off to syncInvoiceState() for the
     * status/journal transition.
     */
    private function applyReceivedQuantities(PurchaseInvoice $invoice, array $receivedByLineId, ?int $by): PurchaseInvoice
    {
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

        return $this->syncInvoiceState($invoice, $by);
    }

    /**
     * One line, untouched since it was ordered, flipped straight to fully
     * received (or back) without going through the granular quantity form.
     * ON only works while the line has zero receipt history; OFF only works
     * while that history is exactly the single event this same toggle
     * created (flagged via_toggle) — the moment a real recordReceipt() event
     * or a return exists, the toggle is out of scope and the caller must use
     * the quantity-based flow instead (never silently erasing real history).
     */
    public function toggleReceived(PurchaseInvoiceLine $line, bool $received, ?int $by = null): PurchaseInvoice
    {
        return DB::transaction(function () use ($line, $received, $by) {
            $invoice = $line->invoice;

            if ($received) {
                if ($line->received_qty > 0 || $line->receiptLines()->exists()) {
                    throw new InvalidArgumentException('این ردیف قبلاً دریافتی ثبت شده؛ برای تغییر از فرم دریافت جزئی استفاده کنید.');
                }

                $receipt = PurchaseInvoiceReceipt::create([
                    'uuid' => (string) Str::uuid(),
                    'purchase_invoice_id' => $invoice->id,
                    'received_at' => now()->toDateString(),
                    'notes' => 'دریافت کامل با سوییچ سریع',
                    'created_by' => $by,
                ]);

                $receipt->lines()->create([
                    'purchase_invoice_line_id' => $line->id,
                    'qty' => $line->qty,
                    'via_toggle' => true,
                ]);

                $line->costItem->costHistory()->create([
                    'unit_cost' => $line->unit_price,
                    'landed_unit_cost' => $line->landed_unit_cost,
                    'source' => 'invoice',
                    'source_id' => $line->id,
                    'effective_at' => $invoice->invoice_date,
                    'created_by' => $by,
                ]);
                $this->cascadeToVariations($line, $line->landed_unit_cost, $by);

                $line->update(['received_qty' => $line->qty]);
            } else {
                $receiptLines = $line->receiptLines()->get();

                if ($receiptLines->count() !== 1 || ! $receiptLines->first()->via_toggle || $line->returned_qty > 0) {
                    throw new InvalidArgumentException('این ردیف دارای سابقهٔ دریافت واقعی است؛ برای اصلاح از فرم دریافت جزئی استفاده کنید.');
                }

                $receiptLine = $receiptLines->first();
                $receipt = $receiptLine->receipt;

                // Safe only because the guard above proved nothing else happened
                // since this exact crossing — undo the cost_history it wrote too.
                $line->costItem->costHistory()
                    ->where('source', 'invoice')->where('source_id', $line->id)
                    ->where('effective_at', $invoice->invoice_date)->delete();

                $product = $line->product;
                if ($product && $product->type === 'variable') {
                    foreach ($product->variations as $variation) {
                        $mapping = $this->mappingResolver->resolveOrCreate($variation);
                        $mapping->costItem->costHistory()
                            ->where('source', 'invoice')->where('source_id', $line->id)->delete();
                    }
                }

                $receiptLine->delete();
                if ($receipt->lines()->count() === 0) {
                    $receipt->delete();
                }

                $line->update(['received_qty' => 0]);
            }

            return $this->syncInvoiceState($invoice, $by);
        });
    }

    /**
     * In-place correction of an already-recorded delivery's quantity (e.g. a
     * miscount at the time of receiving). Never touches cost_history — unit
     * cost isn't affected by a quantity correction, matching how update()
     * handles price corrections with new rows rather than mutation. $newQty
     * of 0 removes the receipt line entirely (and its receipt header, if now
     * empty). Every change is audited via PurchaseInvoiceReceiptLine's
     * LogsActivity with the caller-supplied reason.
     */
    public function updateReceiptLine(PurchaseInvoiceReceiptLine $receiptLine, int $newQty, string $reason, ?int $by = null): PurchaseInvoice
    {
        return DB::transaction(function () use ($receiptLine, $newQty, $reason, $by) {
            if ($newQty < 0) {
                throw new InvalidArgumentException('تعداد نمی‌تواند منفی باشد.');
            }

            // Query fresh rather than trust $receiptLine->invoiceLine — the caller
            // may be holding an already eager-loaded (and by now stale) relation.
            $line = $receiptLine->invoiceLine()->first();
            $invoice = $line->invoice()->first();
            $newCumulative = $line->received_qty - $receiptLine->qty + $newQty;

            if ($newCumulative < $line->returned_qty) {
                throw new InvalidArgumentException("تعداد دریافتی را نمی‌توان کمتر از مقدار برگشت‌خورده ({$line->returned_qty}) کرد.");
            }

            if ($newCumulative > $line->qty) {
                throw new InvalidArgumentException("تعداد دریافتی نمی‌تواند از تعداد سفارش‌شده ({$line->qty}) بیشتر شود.");
            }

            $receipt = $receiptLine->receipt;
            $receiptLine->activityReason = $reason;

            if ($newQty === 0) {
                $receiptLine->delete();
                if ($receipt->lines()->count() === 0) {
                    $receipt->delete();
                }
            } else {
                $receiptLine->update(['qty' => $newQty]);
            }

            $line->update(['received_qty' => $newCumulative]);

            return $this->syncInvoiceState($invoice, $by);
        });
    }

    /**
     * Shared tail of every receiving mutation: recompute draft/partial/received,
     * reverse a posted journal if the invoice just dropped out of "received"
     * (never leave an AP liability on the books for goods no longer fully
     * received), post it if freshly complete, and — mirroring ChannelMapper's
     * auto-resolve of ReviewItem on fix — close any open overdue tracking the
     * moment full receipt is (re)reached.
     */
    private function syncInvoiceState(PurchaseInvoice $invoice, ?int $by): PurchaseInvoice
    {
        $invoice->refresh();
        $wasFullyReceived = $invoice->status === 'received';
        $lines = $invoice->lines;
        $fullyReceived = $lines->every(fn ($l) => $l->received_qty >= $l->qty);
        $anythingReceived = $lines->contains(fn ($l) => $l->received_qty > 0);

        $invoice->update(['status' => $fullyReceived ? 'received' : ($anythingReceived ? 'partial' : 'draft')]);

        if ($fullyReceived && ! $invoice->journal_entry_id) {
            // If this invoice's original idempotency key was already used (a prior
            // full-receive that was since reversed by this same method), post()
            // would just hand back that stale reversed entry — needs a fresh key,
            // exactly like update()'s wasJournaled correction path.
            $everJournaled = JournalEntry::where('idempotency_key', "purchase:{$invoice->uuid}")->exists();
            $this->postJournal($invoice, $by, correction: $everJournaled);
        } elseif (! $fullyReceived && $invoice->journal_entry_id) {
            $this->poster->reverse($invoice->journalEntry, "برگشت دریافت فاکتور خرید #{$invoice->id}", $by);
            $invoice->update(['journal_entry_id' => null]);
        }

        if ($fullyReceived && ! $wasFullyReceived) {
            ReviewItem::where('type', 'purchase_receipt_overdue')
                ->where('subject_type', 'purchase_invoice')
                ->where('subject_id', $invoice->id)
                ->where('status', 'open')
                ->update(['status' => 'resolved', 'resolved_by' => $by, 'resolved_at' => now()]);

            $this->alerts->resolve('purchase_receipt_overdue', $invoice);
        }

        return $invoice->refresh()->load('lines');
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
