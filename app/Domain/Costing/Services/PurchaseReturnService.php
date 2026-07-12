<?php

namespace App\Domain\Costing\Services;

use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Costing\Models\PurchaseInvoice;
use App\Domain\Costing\Models\PurchaseReturn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Goods physically sent back to a supplier. Posts its own journal entry
 * (Dr AP / Cr Inventory) — the original invoice's journal is never touched,
 * so a return is always additive/auditable rather than a silent correction.
 * The resulting AP balance (possibly negative) is the supplier's usable
 * credit; PayablesService's existing running balance already reflects it.
 */
class PurchaseReturnService
{
    private const AP = '2000';

    private const INVENTORY = '1300';

    public function __construct(private readonly JournalPoster $poster) {}

    /** $lines: [['line_id' => int, 'qty' => int], ...] */
    public function create(PurchaseInvoice $invoice, array $lines, string $reason, ?int $by = null): PurchaseReturn
    {
        return DB::transaction(function () use ($invoice, $lines, $reason, $by) {
            $return = PurchaseReturn::create([
                'uuid' => (string) Str::uuid(),
                'purchase_invoice_id' => $invoice->id,
                'reason' => $reason,
                'created_by' => $by,
            ]);

            $total = 0;

            foreach ($lines as $lineData) {
                $qty = (int) $lineData['qty'];
                if ($qty <= 0) {
                    continue;
                }

                $line = $invoice->lines->firstWhere('id', $lineData['line_id']);
                if (! $line) {
                    throw new InvalidArgumentException('ردیف فاکتور یافت نشد.');
                }
                if ($qty > $line->returnableQty()) {
                    throw new InvalidArgumentException("تعداد قابل بازگشت ردیف «{$line->costItem->name}» بیشتر از {$line->returnableQty()} نیست.");
                }

                $return->lines()->create([
                    'purchase_invoice_line_id' => $line->id,
                    'qty' => $qty,
                    'unit_cost' => $line->landed_unit_cost,
                ]);

                $line->update(['returned_qty' => $line->returned_qty + $qty]);
                $total += $qty * $line->landed_unit_cost;
            }

            if ($total <= 0) {
                throw new InvalidArgumentException('حداقل یک ردیف با تعداد معتبر برای بازگشت لازم است.');
            }

            $entry = $this->poster->post([
                'entry_date' => Carbon::now(JalaliPeriod::TIMEZONE),
                'description' => "برگشت از خرید #{$invoice->id} — {$invoice->supplier->name}",
                'idempotency_key' => "purchase_return:{$return->uuid}",
                'source' => $return,
                'created_by' => $by,
            ], [
                ['account' => self::AP, 'debit' => $total, 'party_id' => $invoice->supplier_party_id],
                ['account' => self::INVENTORY, 'credit' => $total],
            ]);

            $return->update(['journal_entry_id' => $entry->id]);

            return $return->load('lines', 'journalEntry.lines');
        });
    }
}
