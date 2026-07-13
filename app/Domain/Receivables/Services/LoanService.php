<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Receivables\Models\Loan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoanService
{
    public function __construct(private readonly JournalPoster $poster) {}

    public function receive(Party $lender, int $principal, int $bankAccountId, Carbon $receivedAt): Loan
    {
        return DB::transaction(function () use ($lender, $principal, $bankAccountId, $receivedAt) {
            $loan = Loan::create([
                'uuid' => (string) Str::uuid(),
                'party_id' => $lender->id,
                'principal' => $principal,
                'received_at' => $receivedAt->toDateString(),
                'bank_account_id' => $bankAccountId,
            ]);

            $entry = $this->poster->post([
                'entry_date' => $receivedAt,
                'description' => "دریافت وام از {$lender->name}",
                'idempotency_key' => "loan:{$loan->uuid}",
                'source' => $loan,
            ], [
                ['account' => BankAccount::findOrFail($bankAccountId)->account_id, 'debit' => $principal],
                ['account' => AccountCode::LoansPayable, 'credit' => $principal, 'party_id' => $lender->id],
            ]);

            $loan->update(['journal_entry_id' => $entry->id]);

            return $loan;
        });
    }

    /** Pay one installment: principal reduces the loan, interest hits finance cost. */
    public function payInstallment(Loan $loan, int $amount, int $principalPart, int $bankAccountId, Carbon $paidAt): void
    {
        DB::transaction(function () use ($loan, $amount, $principalPart, $bankAccountId, $paidAt) {
            $interest = $amount - $principalPart;

            $installment = $loan->installments()->create([
                'amount' => $amount,
                'principal_part' => $principalPart,
                'interest_part' => $interest,
                'paid_at' => $paidAt->toDateString(),
                'status' => 'paid',
            ]);

            $lines = [['account' => AccountCode::LoansPayable, 'debit' => $principalPart, 'party_id' => $loan->party_id]];
            if ($interest > 0) {
                $lines[] = ['account' => AccountCode::FinanceCost, 'debit' => $interest];
            }
            $lines[] = ['account' => BankAccount::findOrFail($bankAccountId)->account_id, 'credit' => $amount];

            $entry = $this->poster->post([
                'entry_date' => $paidAt,
                'description' => "پرداخت قسط وام {$loan->uuid}",
                'idempotency_key' => "loan_installment:{$loan->uuid}:{$installment->id}",
                'source' => $loan,
            ], $lines);

            $installment->update(['journal_entry_id' => $entry->id]);

            $paidPrincipal = (int) $loan->installments()->sum('principal_part');
            if ($paidPrincipal >= $loan->principal) {
                $loan->update(['status' => 'closed']);
            }
        });
    }
}
