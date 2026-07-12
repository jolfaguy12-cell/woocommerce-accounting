<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Receivables\Models\SupplierCreditAdjustment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Manual "retained balance" supplier credit — for cases that are neither a
 * return (goods, see PurchaseReturnService) nor a cash refund (see
 * PaymentRecorder::receiveRefund()): an opening-balance migration, or a
 * goodwill credit the supplier granted outside of any specific transaction.
 * Mirrors CreditOrderService::openManual()'s customer-side manual credit.
 */
class SupplierCreditService
{
    private const AP = '2000';

    private const OTHER_INCOME = '4900';

    public function __construct(private readonly JournalPoster $poster) {}

    public function recordManualCredit(Party $party, int $amount, string $description, ?int $by = null): SupplierCreditAdjustment
    {
        return DB::transaction(function () use ($party, $amount, $description, $by) {
            $adjustment = SupplierCreditAdjustment::create([
                'uuid' => (string) Str::uuid(),
                'party_id' => $party->id,
                'amount' => $amount,
                'description' => $description,
                'created_by' => $by,
            ]);

            $entry = $this->poster->post([
                'entry_date' => Carbon::now(JalaliPeriod::TIMEZONE),
                'description' => "اعتبار دستی: {$description} — {$party->name}",
                'idempotency_key' => "supplier_credit:{$adjustment->uuid}",
                'source' => $adjustment,
                'created_by' => $by,
            ], [
                ['account' => self::AP, 'debit' => $amount, 'party_id' => $party->id],
                ['account' => self::OTHER_INCOME, 'credit' => $amount],
            ]);

            $adjustment->update(['journal_entry_id' => $entry->id]);

            return $adjustment->load('journalEntry.lines');
        });
    }
}
