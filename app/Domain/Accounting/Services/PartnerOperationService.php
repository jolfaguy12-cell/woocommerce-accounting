<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Exceptions\OperationStateException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\PartnerOperation;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\CounterAccountPolicy;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Accounting\Support\OperationPolicy;
use App\Domain\Accounting\Support\OperationStatus;
use App\Domain\Accounting\Support\PartnerOperationType;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Receivables\Models\LoanInstallment;
use App\Domain\Receivables\Services\LoanService;
use App\Domain\Receivables\Support\InterestMethod;
use App\Domain\Receivables\Support\LoanDirection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * A partner's dealings with the company, each kept on its own accounts.
 *
 * The temptation is to treat all nine as "money in / money out" and be done. That
 * is exactly the mistake: in a bank statement a partner's capital contribution,
 * their loan to the company and their drawings are the same event — cash arriving
 * or leaving. In the accounts they could not be more different. One increases what
 * they OWN, one increases what they are OWED, one reduces their share of profit.
 * Collapse them and the partner report can no longer tell an owner from a creditor,
 * and no one can answer "how much of this business is actually mine?".
 *
 * The account for each type lives in PartnerOperationType — not here, and never
 * inline in a controller.
 */
class PartnerOperationService extends FinancialOperationService
{
    public function __construct(
        JournalPoster $poster,
        OperationPolicy $policy,
        private readonly PartyLedgerService $ledger,
        private readonly CounterAccountPolicy $counterAccounts,
        private readonly LoanService $loans,
    ) {
        parent::__construct($poster, $policy);
    }

    /**
     * $data: party, type, amount, operation_date, description
     *        [bank_account_id, counter_account_id, reference, notes, created_by]
     */
    public function create(array $data): PartnerOperation
    {
        /** @var Party $party */
        $party = $data['party'];
        $type = $data['type'] instanceof PartnerOperationType
            ? $data['type']
            : PartnerOperationType::from($data['type']);
        $amount = (int) $data['amount'];

        $bankAccount = isset($data['bank_account_id'])
            ? BankAccount::with('account')->findOrFail($data['bank_account_id'])
            : null;
        $counter = isset($data['counter_account_id'])
            ? Account::findOrFail($data['counter_account_id'])
            : null;

        $this->assertRecordable($party, $type, $amount, $bankAccount, $counter);

        $date = $data['operation_date'] instanceof Carbon
            ? $data['operation_date']
            : Carbon::parse($data['operation_date'], JalaliPeriod::TIMEZONE);

        return DB::transaction(function () use ($data, $party, $type, $amount, $bankAccount, $counter, $date) {
            $operation = PartnerOperation::create([
                'uuid' => (string) Str::uuid(),
                'status' => OperationStatus::Draft->value,
                'party_id' => $party->id,
                'type' => $type,
                'amount' => $amount,
                'bank_account_id' => $bankAccount?->id,
                'counter_account_id' => $counter?->id,
                'operation_date' => $date->toDateString(),
                'jalali_period' => JalaliPeriod::fromDate($date),
                'description' => $data['description'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                // Held until the operation posts: an operation awaiting approval may sit
                // for days, and the terms approved must be the terms recorded.
                'loan_terms' => $type->createsLoan() ? $this->loanTerms($data) : null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            return $this->finalizeCreation($operation, $data['created_by'] ?? null);
        });
    }

    /** Expense accounts a partner can be reimbursed for — the same allowlist direct operations use. */
    public function reimbursableAccounts()
    {
        return $this->counterAccounts->eligible()->where('type', 'expense')->values();
    }

    /**
     * The most that can be paid out for the types that settle an existing balance.
     * Paying more profit than was declared, or settling more current account than we
     * owe, does not clear a balance — it pushes it negative and quietly turns the
     * partner into our debtor.
     */
    public function cap(Party $party, PartnerOperationType $type): ?int
    {
        return match ($type) {
            PartnerOperationType::ProfitPayablePayment => max(0, $this->ledger->balanceOn($party, AccountCode::PartnerProfitPayable)),
            PartnerOperationType::CurrentAccountSettlement => max(0, $this->ledger->balanceOn($party, AccountCode::PartnerCurrentAccount)),
            default => null, // no natural ceiling: a partner can always contribute more capital
        };
    }

    private function assertRecordable(Party $party, PartnerOperationType $type, int $amount, ?BankAccount $bank, ?Account $counter): void
    {
        if ($amount < 1) {
            throw new InvalidArgumentException('مبلغ عملیات باید بزرگ‌تر از صفر باشد.');
        }

        if (! $party->hasRole('partner')) {
            throw new InvalidArgumentException("«{$party->name}» نقش شریک ندارد. ابتدا نقش شریک را برای این طرف حساب فعال کنید.");
        }

        if ($type->movesCash()) {
            if (! $bank) {
                throw new InvalidArgumentException("برای «{$type->label()}» باید حساب بانکی مشخص شود.");
            }
            if (! $bank->is_active) {
                throw new InvalidArgumentException("حساب «{$bank->name}» غیرفعال است.");
            }
        }

        if ($type->needsCounterAccount()) {
            if (! $counter) {
                throw new InvalidArgumentException('برای بازپرداخت هزینه شریک، باید مشخص کنید کدام هزینه را پرداخت کرده است.');
            }
            // Gated through the same allowlist as direct operations: a reimbursement
            // is an expense, and it may not be used to reach a control account.
            if (! $this->reimbursableAccounts()->contains('id', $counter->id)) {
                throw new InvalidArgumentException("حساب «{$counter->name}» برای بازپرداخت هزینه شریک مجاز نیست؛ فقط حساب‌های هزینه مجاز قابل انتخاب‌اند.");
            }
        }

        $cap = $this->cap($party, $type);

        if ($cap !== null && $amount > $cap) {
            throw new InvalidArgumentException(
                "مبلغ بیشتر از مانده «{$type->label()}» است: حداکثر ".number_format($cap).' تومان.'
            );
        }
    }

    /**
     * One entry per operation. The party-facing line always carries the party_id —
     * that is what makes the operation show up in the partner's unified statement,
     * and a partner operation invisible on the partner's own statement would be
     * worse than no operation at all.
     */
    protected function lines(Model $operation): array
    {
        /** @var PartnerOperation $operation */
        $operation->loadMissing(['bankAccount', 'party']);

        $type = $operation->type;
        $amount = $operation->amount;
        $partyId = $operation->party_id;
        $bank = $operation->bankAccount?->account_id;

        $party = fn (AccountCode $code, string $side, ?string $memo = null) => [
            'account' => $code,
            $side => $amount,
            'party_id' => $partyId,
            'memo' => $memo ?? $type->label(),
        ];

        return match ($type) {
            // Capital in: the partner's stake grows. Not revenue — the company did
            // not earn this, it was given it by an owner.
            PartnerOperationType::Contribution => [
                ['account' => $bank, 'debit' => $amount],
                $party(AccountCode::Capital, 'credit'),
            ],

            // Capital out: the stake itself shrinks.
            PartnerOperationType::CapitalReduction => [
                $party(AccountCode::Capital, 'debit'),
                ['account' => $bank, 'credit' => $amount],
            ],

            // Drawings, kept apart from capital so the original stake stays legible.
            PartnerOperationType::Withdrawal => [
                $party(AccountCode::PartnerWithdrawal, 'debit'),
                ['account' => $bank, 'credit' => $amount],
            ],

            // The partner paid a company expense out of their own pocket: the expense
            // is ours, and we now owe them for it. No cash moves here — settling it
            // later is CurrentAccountSettlement.
            PartnerOperationType::ExpenseReimbursement => [
                ['account' => $operation->counter_account_id, 'debit' => $amount, 'memo' => $operation->description],
                $party(AccountCode::PartnerCurrentAccount, 'credit', 'هزینه پرداخت‌شده توسط شریک'),
            ],

            // Declaring a profit share: the earnings the company has ACCUMULATED become a
            // debt owed to the partner. Still no cash — the money is owed, not yet paid.
            //
            // Taken out of retained earnings (3200), never out of Capital (3000). Capital
            // is what the partner PUT IN, and paying them their earnings does not give any
            // of it back. Debiting 3000 would make a partner who has been paid their share
            // look as though they had progressively withdrawn their stake — after enough
            // profitable years, a founder who invested 100m and was paid 100m of earnings
            // would read as owning nothing at all. No party_id: retained earnings is a
            // company-wide pool, not a per-partner balance.
            PartnerOperationType::ProfitDistribution => [
                ['account' => AccountCode::RetainedEarnings, 'debit' => $amount,
                    'memo' => "تخصیص سود به {$operation->party->name}"],
                $party(AccountCode::PartnerProfitPayable, 'credit'),
            ],

            // Paying out a share that was already declared above («پرداخت سود شریک»).
            PartnerOperationType::ProfitPayablePayment => [
                $party(AccountCode::PartnerProfitPayable, 'debit'),
                ['account' => $bank, 'credit' => $amount],
            ],

            PartnerOperationType::CurrentAccountSettlement => [
                $party(AccountCode::PartnerCurrentAccount, 'debit'),
                ['account' => $bank, 'credit' => $amount],
            ],

            // Never reached: a partner loan is a LOAN, and LoanService posts it. See
            // postEntry(). Listed so the match stays exhaustive and a future type cannot
            // fall through this switch silently.
            PartnerOperationType::LoanFromPartner,
            PartnerOperationType::LoanToPartner => throw new LogicException(
                'A partner loan is posted by LoanService, not here — see PartnerOperationService::postEntry().'
            ),
        };
    }

    /**
     * A partner loan does not post its own lines: it creates a real Loan contract, and
     * THAT posts — once. This operation then records the same event on the partner's file
     * by pointing at the loan it created.
     *
     * Before this, the two loan types posted a partner-shaped entry straight to 2200/1600
     * with no Loan row behind it: the money was in the ledger, and the loan had no maturity
     * date, no interest and no schedule — nothing to repay against, and nothing to say the
     * repayment was ever due. And posting an entry HERE as well as in LoanService would put
     * the same disbursement in the books twice, in a way that still balances perfectly.
     */
    protected function postEntry(Model $operation, ?int $by): JournalEntry
    {
        /** @var PartnerOperation $operation */
        if (! $operation->type->createsLoan()) {
            return parent::postEntry($operation, $by);
        }

        $terms = (array) ($operation->loan_terms ?? []);

        $loan = $this->loans->create([
            'party' => $operation->party,
            'direction' => $operation->type === PartnerOperationType::LoanFromPartner
                ? LoanDirection::Payable      // they lent to us — «وام دریافتی»
                : LoanDirection::Receivable,  // we lent to them — «وام پرداختی»
            'principal' => $operation->amount,
            'bank_account_id' => $operation->bank_account_id,
            'received_at' => $operation->operation_date,
            'maturity_date' => $terms['maturity_date'] ?? null,
            'interest_method' => $terms['interest_method'] ?? null,
            'interest_rate' => $terms['interest_rate'] ?? null,
            'interest_amount' => $terms['interest_amount'] ?? null,
            'installment_count' => $terms['installment_count'] ?? 0,
            'reference' => $operation->reference,
            'notes' => $operation->description,
            'created_by' => $operation->created_by ?? $by,
            // This operation has already cleared its own approval gate; making the loan sit
            // through a second, identical approval of the very same money would deadlock it.
        ], requireApproval: false);

        $operation->forceFill(['loan_id' => $loan->id])->save();

        return $loan->journalEntry;
    }

    /** A loan with repayments behind it cannot be undone from this end. */
    protected function assertReversible(Model $operation): void
    {
        /** @var PartnerOperation $operation */
        $loan = $operation->loan;

        if ($loan && $loan->installments()->where('status', LoanInstallment::PAID)->exists()) {
            throw new OperationStateException(
                'این وام اقساط پرداخت‌شده دارد. ابتدا اقساط را از صفحهٔ وام برگشت بزنید، سپس این عملیات را.'
            );
        }
    }

    /**
     * The shared journal entry has just been reversed by the base class. The loan must
     * follow it into the reversed state — but must NOT post a reversal of its own, or the
     * money would come back twice.
     */
    protected function afterReversal(Model $operation, JournalEntry $reversal): void
    {
        /** @var PartnerOperation $operation */
        if ($loan = $operation->loan) {
            $this->loans->markReversedByOwner(
                $loan,
                $reversal,
                (string) $operation->reversal_reason,
                User::findOrFail($operation->reversed_by),
            );
        }
    }

    /** @return array<string, mixed> */
    private function loanTerms(array $data): array
    {
        return [
            'maturity_date' => $data['maturity_date'] ?? null,
            'interest_method' => $data['interest_method'] ?? InterestMethod::None->value,
            'interest_rate' => $data['interest_rate'] ?? null,
            'interest_amount' => $data['interest_amount'] ?? null,
            'installment_count' => (int) ($data['installment_count'] ?? 0),
        ];
    }

    protected function description(Model $operation): string
    {
        /** @var PartnerOperation $operation */
        $operation->loadMissing('party');

        return "{$operation->type->label()} — {$operation->party->name}: {$operation->description}";
    }

    protected function idempotencyKey(Model $operation): string
    {
        return "partner_operation:{$operation->uuid}";
    }

    protected function entryDate(Model $operation): Carbon
    {
        return Carbon::parse($operation->operation_date, JalaliPeriod::TIMEZONE);
    }

    protected function outflows(Model $operation): array
    {
        /** @var PartnerOperation $operation */
        return $operation->type->isOutflow() && $operation->bank_account_id
            ? [$operation->bank_account_id => $operation->amount]
            : [];
    }
}
