<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountTransaction;
use App\Domain\Accounting\Support\CounterAccountPolicy;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Accounting\Support\OperationPolicy;
use App\Domain\Accounting\Support\OperationStatus;
use App\Domain\Expenses\Models\BankAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A direct deposit into, or withdrawal from, one of our accounts.
 *
 * The counter-account is mandatory and is validated here, not just in the form:
 * money that enters an account has to come from somewhere, and an operation that
 * could adjust a balance without saying where the other side went would be a
 * plug — the fastest way to make a ledger stop meaning anything.
 */
class AccountTransactionService extends FinancialOperationService
{
    public function __construct(
        JournalPoster $poster,
        OperationPolicy $policy,
        private readonly CounterAccountPolicy $counterAccounts,
    ) {
        parent::__construct($poster, $policy);
    }

    /**
     * $data: bank_account_id, direction (in|out), counter_account_id, purpose,
     *        amount, transaction_date, description,
     *        [party_id, method, reference, notes, created_by]
     */
    public function create(array $data): AccountTransaction
    {
        $bankAccount = BankAccount::with('account')->findOrFail($data['bank_account_id']);
        $counter = Account::findOrFail($data['counter_account_id']);
        $direction = $data['direction'];
        $amount = (int) $data['amount'];

        $this->assertRecordable($bankAccount, $counter, $direction, $amount);

        $date = $data['transaction_date'] instanceof Carbon
            ? $data['transaction_date']
            : Carbon::parse($data['transaction_date'], JalaliPeriod::TIMEZONE);

        return DB::transaction(function () use ($data, $bankAccount, $counter, $direction, $amount, $date) {
            $transaction = AccountTransaction::create([
                'uuid' => (string) Str::uuid(),
                // Explicit, not left to the column default — see AccountTransferService.
                'status' => OperationStatus::Draft->value,
                'bank_account_id' => $bankAccount->id,
                'direction' => $direction,
                'counter_account_id' => $counter->id,
                'purpose' => $data['purpose'],
                'party_id' => $data['party_id'] ?? null,
                'amount' => $amount,
                'transaction_date' => $date->toDateString(),
                'jalali_period' => JalaliPeriod::fromDate($date),
                'method' => $data['method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            return $this->finalizeCreation($transaction, $data['created_by'] ?? null);
        });
    }

    private function assertRecordable(BankAccount $bankAccount, Account $counter, string $direction, int $amount): void
    {
        if (! in_array($direction, [AccountTransaction::DIRECTION_IN, AccountTransaction::DIRECTION_OUT], true)) {
            throw new InvalidArgumentException("جهت تراکنش نامعتبر است: [{$direction}].");
        }

        if ($amount < 1) {
            throw new InvalidArgumentException('مبلغ تراکنش باید بزرگ‌تر از صفر باشد.');
        }

        if (! $bankAccount->is_active) {
            throw new InvalidArgumentException("حساب «{$bankAccount->name}» غیرفعال است.");
        }

        // Both lines on one account nets to nothing: it would post a balanced,
        // completely meaningless entry, and the balance would not move at all.
        if ($counter->id === $bankAccount->account_id) {
            throw new InvalidArgumentException('حساب مقابل نمی‌تواند خودِ همان حساب باشد.');
        }

        // THE gate. A direct operation may reach nothing but income, expense and
        // classified adjustments — never a control account, whose balance belongs
        // to a typed workflow (payments, invoices, loans, payroll, partner ops).
        // Enforced here rather than in the form, so no caller can bypass it.
        $this->counterAccounts->assertEligible($counter);
    }

    protected function lines(Model $operation): array
    {
        /** @var AccountTransaction $operation */
        $operation->loadMissing(['bankAccount', 'counterAccount']);

        $bankLine = [
            'account' => $operation->bankAccount->account_id,
            'memo' => $operation->purposeLabel(),
        ];

        // The party belongs on the counter line: the bank line is our own account,
        // and it is the other side that says who the money came from or went to.
        $counterLine = [
            'account' => $operation->counter_account_id,
            'party_id' => $operation->party_id,
            'memo' => $operation->description,
        ];

        return $operation->isDeposit()
            ? [$bankLine + ['debit' => $operation->amount], $counterLine + ['credit' => $operation->amount]]
            : [$counterLine + ['debit' => $operation->amount], $bankLine + ['credit' => $operation->amount]];
    }

    protected function description(Model $operation): string
    {
        /** @var AccountTransaction $operation */
        $prefix = $operation->isDeposit() ? 'واریز مستقیم' : 'برداشت مستقیم';

        return "{$prefix}: {$operation->description}";
    }

    protected function idempotencyKey(Model $operation): string
    {
        return "account_transaction:{$operation->uuid}";
    }

    protected function entryDate(Model $operation): Carbon
    {
        return Carbon::parse($operation->transaction_date, JalaliPeriod::TIMEZONE);
    }

    protected function outflows(Model $operation): array
    {
        /** @var AccountTransaction $operation */
        return $operation->isDeposit() ? [] : [$operation->bank_account_id => $operation->amount];
    }
}
