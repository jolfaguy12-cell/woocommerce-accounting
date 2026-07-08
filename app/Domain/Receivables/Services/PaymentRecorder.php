<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Receivables\Models\CreditOrder;
use App\Domain\Receivables\Models\PartyPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentRecorder
{
    private const AR = '1200';

    private const CUSTOMER_CREDIT = '2400';

    public function __construct(private readonly JournalPoster $poster) {}

    /**
     * Customer payment in: settles AR up to what is owed; any excess is held
     * as customer credit (liability), never lost.
     */
    public function receive(Party $party, int $amount, int $bankAccountId, ?CreditOrder $creditOrder = null, ?int $by = null): PartyPayment
    {
        return DB::transaction(function () use ($party, $amount, $bankAccountId, $creditOrder, $by) {
            $payment = PartyPayment::create([
                'uuid' => (string) Str::uuid(),
                'party_id' => $party->id,
                'direction' => 'in',
                'amount' => $amount,
                'bank_account_id' => $bankAccountId,
                'applied_type' => $creditOrder?->getMorphClass(),
                'applied_id' => $creditOrder?->id,
                'paid_at' => Carbon::now(JalaliPeriod::TIMEZONE)->toDateString(),
                'created_by' => $by,
            ]);

            $settled = $amount;
            $excess = 0;

            if ($creditOrder) {
                $settled = min($amount, $creditOrder->remaining());
                $excess = $amount - $settled;

                $creditOrder->update([
                    'paid_total' => $creditOrder->paid_total + $settled,
                    'status' => $creditOrder->paid_total + $settled >= $creditOrder->total_due ? 'settled' : 'open',
                ]);
            }

            $lines = [
                ['account' => BankAccount::findOrFail($bankAccountId)->account_id, 'debit' => $amount],
                ['account' => self::AR, 'credit' => $settled, 'party_id' => $party->id],
            ];
            if ($excess > 0) {
                $lines[] = ['account' => self::CUSTOMER_CREDIT, 'credit' => $excess, 'party_id' => $party->id];
            }

            $entry = $this->poster->post([
                'entry_date' => Carbon::now(JalaliPeriod::TIMEZONE),
                'description' => "دریافت از {$party->name}",
                'idempotency_key' => "payment:{$payment->uuid}",
                'source' => $payment,
                'created_by' => $by,
            ], $lines);

            $payment->update(['journal_entry_id' => $entry->id]);

            return $payment->load('journalEntry.lines');
        });
    }
}
