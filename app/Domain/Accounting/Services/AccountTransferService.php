<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\AccountTransfer;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Accounting\Support\OperationStatus;
use App\Domain\Expenses\Models\BankAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Moving money between two of our own accounts.
 *
 * ONE journal entry carries the whole thing — both sides and the fee. That is
 * not a stylistic choice: JournalPoster::reverse() unwinds an entry, so a
 * transfer split across two entries could be half-reversed, leaving money that
 * exists in neither account. One entry makes that state unreachable.
 */
class AccountTransferService extends FinancialOperationService
{
    /**
     * $data: from_bank_account_id, to_bank_account_id, amount, transfer_date,
     *        [bank_fee, method, reference, notes, created_by]
     */
    public function create(array $data): AccountTransfer
    {
        $from = BankAccount::with('account')->findOrFail($data['from_bank_account_id']);
        $to = BankAccount::with('account')->findOrFail($data['to_bank_account_id']);

        $amount = (int) $data['amount'];
        $fee = (int) ($data['bank_fee'] ?? 0);

        $this->assertTransferable($from, $to, $amount, $fee);

        $date = $data['transfer_date'] instanceof Carbon
            ? $data['transfer_date']
            : Carbon::parse($data['transfer_date'], JalaliPeriod::TIMEZONE);

        return DB::transaction(function () use ($data, $from, $to, $amount, $fee, $date) {
            $transfer = AccountTransfer::create([
                'uuid' => (string) Str::uuid(),
                // Explicit, not left to the column default: a default only exists in
                // the DB, so the freshly-created model would carry no status at all
                // until it was re-read — and the lifecycle asks for it immediately.
                'status' => OperationStatus::Draft->value,
                'from_bank_account_id' => $from->id,
                'to_bank_account_id' => $to->id,
                'amount' => $amount,
                'bank_fee' => $fee,
                'transfer_date' => $date->toDateString(),
                'jalali_period' => JalaliPeriod::fromDate($date),
                'method' => $data['method'] ?? 'internal',
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            return $this->finalizeCreation($transfer, $data['created_by'] ?? null);
        });
    }

    private function assertTransferable(BankAccount $from, BankAccount $to, int $amount, int $fee): void
    {
        if ($from->id === $to->id) {
            throw new InvalidArgumentException('مبدأ و مقصد انتقال نمی‌توانند یک حساب باشند.');
        }

        foreach ([$from, $to] as $account) {
            if (! $account->is_active) {
                throw new InvalidArgumentException("حساب «{$account->name}» غیرفعال است و نمی‌توان با آن انتقال ثبت کرد.");
            }
        }

        if ($amount < 1) {
            throw new InvalidArgumentException('مبلغ انتقال باید بزرگ‌تر از صفر باشد.');
        }

        if ($fee < 0) {
            throw new InvalidArgumentException('کارمزد نمی‌تواند منفی باشد.');
        }
    }

    /**
     * The transfer itself moves value between two asset accounts, so it creates
     * neither income nor expense — the totals of the business are untouched. The
     * bank fee is the one part that IS an expense, and it is posted as its own
     * line so it can never hide inside the transferred amount.
     *
     * Source and fee are separate credit lines on the same account, so the bank
     * ledger reads the way a bank statement does: the transfer, then the fee.
     */
    protected function lines(Model $operation): array
    {
        /** @var AccountTransfer $operation */
        $operation->loadMissing(['fromBankAccount.account', 'toBankAccount.account']);

        $from = $operation->fromBankAccount;
        $to = $operation->toBankAccount;

        $lines = [
            [
                'account' => $to->account_id,
                'debit' => $operation->amount,
                'memo' => "انتقال از {$from->name}",
            ],
            [
                'account' => $from->account_id,
                'credit' => $operation->amount,
                'memo' => "انتقال به {$to->name}",
            ],
        ];

        if ($operation->bank_fee > 0) {
            $lines[] = [
                'account' => AccountCode::BankFee,
                'debit' => $operation->bank_fee,
                'memo' => "کارمزد انتقال به {$to->name}",
            ];
            $lines[] = [
                'account' => $from->account_id,
                'credit' => $operation->bank_fee,
                'memo' => "کارمزد انتقال به {$to->name}",
            ];
        }

        return $lines;
    }

    protected function description(Model $operation): string
    {
        /** @var AccountTransfer $operation */
        $operation->loadMissing(['fromBankAccount', 'toBankAccount']);

        return "انتقال وجه از {$operation->fromBankAccount->name} به {$operation->toBankAccount->name}";
    }

    protected function idempotencyKey(Model $operation): string
    {
        return "account_transfer:{$operation->uuid}";
    }

    protected function entryDate(Model $operation): Carbon
    {
        return Carbon::parse($operation->transfer_date, JalaliPeriod::TIMEZONE);
    }

    protected function outflows(Model $operation): array
    {
        /** @var AccountTransfer $operation */
        return [$operation->from_bank_account_id => $operation->totalOutflow()];
    }
}
