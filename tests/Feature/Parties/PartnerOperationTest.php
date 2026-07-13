<?php

use App\Domain\Accounting\Models\PartnerOperation;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\PartnerOperationService;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\PartnerOperationType;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Models\User;
use App\Support\Design\TableQuery;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->admin = User::factory()->create()->assignRole('admin');
    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);
    $this->service = app(PartnerOperationService::class);
    $this->ledger = app(PartyLedgerService::class);

    $this->partner = Party::create(['type' => 'partner', 'name' => 'شریک اول']);

    $this->run = fn (PartnerOperationType $type, int $amount, array $extra = []) => $this->service->create(array_merge([
        'party' => $this->partner,
        'type' => $type,
        'amount' => $amount,
        'operation_date' => now(),
        'description' => 'آزمون',
        'bank_account_id' => $type->movesCash() ? $this->bank->id : null,
        'created_by' => $this->admin->id,
    ], $extra));
});

/** Each type must land on its OWN accounts — that is the entire point of the enum. */
it('posts each operation type to its own accounts', function () {
    // Capital in: the partner's stake grows. Cash arrives, equity rises.
    ($this->run)(PartnerOperationType::Contribution, 10_000_000);
    expect($this->ledger->balanceOn($this->partner, AccountCode::Capital))->toBe(10_000_000)
        ->and($this->bank->account->balance())->toBe(10_000_000);

    // Drawings: tracked APART from capital, so the original stake stays legible.
    ($this->run)(PartnerOperationType::Withdrawal, 1_000_000);
    expect($this->ledger->balanceOn($this->partner, AccountCode::PartnerWithdrawal))->toBe(1_000_000)
        ->and($this->ledger->balanceOn($this->partner, AccountCode::Capital))->toBe(10_000_000); // untouched

    // A loan is NOT a contribution: it must be repaid and never becomes their stake.
    ($this->run)(PartnerOperationType::LoanFromPartner, 5_000_000);
    expect($this->ledger->loanPayable($this->partner))->toBe(5_000_000)
        ->and($this->ledger->balanceOn($this->partner, AccountCode::Capital))->toBe(10_000_000); // still untouched

    // Lending TO the partner is an asset of ours.
    ($this->run)(PartnerOperationType::LoanToPartner, 2_000_000);
    expect($this->ledger->loanReceivable($this->partner))->toBe(2_000_000);

    // Capital reduction shrinks the stake itself.
    ($this->run)(PartnerOperationType::CapitalReduction, 3_000_000);
    expect($this->ledger->balanceOn($this->partner, AccountCode::Capital))->toBe(7_000_000);
});

it('keeps a capital contribution out of revenue, and drawings out of expenses', function () {
    ($this->run)(PartnerOperationType::Contribution, 10_000_000);
    ($this->run)(PartnerOperationType::Withdrawal, 1_000_000);

    // The company did not EARN the contribution and did not INCUR the withdrawal.
    // Posting them as revenue/expense would inflate profit on the way in and
    // deflate it on the way out — a business that looks profitable purely because
    // its owners keep funding it.
    expect(AccountCode::SalesRevenue->account()->balance())->toBe(0)
        ->and(AccountCode::OtherIncome->account()->balance())->toBe(0)
        ->and(AccountCode::OperatingExpense->account()->balance())->toBe(0);
});

it('declares a profit share as a payable, then pays it out', function () {
    ($this->run)(PartnerOperationType::Contribution, 10_000_000);

    // Declaring: equity becomes a debt to the partner. No cash moves yet.
    $bankBefore = $this->bank->account->balance();
    ($this->run)(PartnerOperationType::ProfitDistribution, 4_000_000);

    expect($this->ledger->balanceOn($this->partner, AccountCode::PartnerProfitPayable))->toBe(4_000_000)
        ->and($this->bank->account->balance())->toBe($bankBefore); // declaring is not paying

    // Paying: the declared debt is settled in cash.
    ($this->run)(PartnerOperationType::ProfitPayablePayment, 4_000_000);

    expect($this->ledger->balanceOn($this->partner, AccountCode::PartnerProfitPayable))->toBe(0)
        ->and($this->bank->account->balance())->toBe($bankBefore - 4_000_000);
});

it('refuses to pay out more profit than was ever declared', function () {
    ($this->run)(PartnerOperationType::Contribution, 10_000_000);
    ($this->run)(PartnerOperationType::ProfitDistribution, 1_000_000);

    // Paying 5m against a 1m declaration does not settle a balance — it pushes the
    // payable negative and quietly turns the partner into our debtor.
    expect(fn () => ($this->run)(PartnerOperationType::ProfitPayablePayment, 5_000_000))
        ->toThrow(InvalidArgumentException::class);

    expect($this->ledger->balanceOn($this->partner, AccountCode::PartnerProfitPayable))->toBe(1_000_000);
});

it('recognises an expense the partner paid personally, then settles their current account', function () {
    $expenseAccount = $this->service->reimbursableAccounts()->firstWhere('code', '6000');

    // They paid a company expense from their own pocket: the expense is OURS, and we
    // now owe them. No cash moves — the money left their wallet, not our bank.
    $bankBefore = $this->bank->account->balance();
    ($this->run)(PartnerOperationType::ExpenseReimbursement, 800_000, [
        'bank_account_id' => null,
        'counter_account_id' => $expenseAccount->id,
    ]);

    expect($expenseAccount->balance())->toBe(800_000)
        ->and($this->ledger->partnerCurrentAccount($this->partner))->toBe(800_000)
        ->and($this->bank->account->balance())->toBe($bankBefore);

    // Settling it: now the cash actually leaves.
    ($this->run)(PartnerOperationType::CurrentAccountSettlement, 800_000);

    expect($this->ledger->partnerCurrentAccount($this->partner))->toBe(0)
        ->and($this->bank->account->balance())->toBe($bankBefore - 800_000);
});

it('refuses a reimbursement against a control account', function () {
    // A reimbursement is an expense. Letting it name any account would make it a
    // second back door into the payables/payroll/loan ledgers.
    expect(fn () => ($this->run)(PartnerOperationType::ExpenseReimbursement, 100_000, [
        'bank_account_id' => null,
        'counter_account_id' => AccountCode::AccountsPayable->account()->id,
    ]))->toThrow(InvalidArgumentException::class);

    expect(PartnerOperation::count())->toBe(0);
});

it('refuses a partner operation for a party who is not a partner', function () {
    $customer = Party::create(['type' => 'customer', 'name' => 'مشتری معمولی']);

    expect(fn () => $this->service->create([
        'party' => $customer,
        'type' => PartnerOperationType::Contribution,
        'amount' => 1_000_000,
        'operation_date' => now(),
        'description' => 'آزمون',
        'bank_account_id' => $this->bank->id,
        'created_by' => $this->admin->id,
    ]))->toThrow(InvalidArgumentException::class);

    expect(PartnerOperation::count())->toBe(0);
});

it('reverses a partner operation without editing the original entry', function () {
    $operation = ($this->run)(PartnerOperationType::Contribution, 10_000_000);
    $original = $operation->journalEntry;

    $this->service->reverse($operation, 'اشتباه ثبت شد', $this->admin);

    expect($operation->fresh()->isReversed())->toBeTrue()
        ->and($original->fresh()->status)->toBe('reversed')
        ->and($original->fresh()->lines->sum('debit'))->toBe(10_000_000) // untouched
        ->and($this->ledger->balanceOn($this->partner, AccountCode::Capital))->toBe(0)
        ->and($this->bank->account->balance())->toBe(0);
});

it('shows every partner operation on the partners unified statement', function () {
    ($this->run)(PartnerOperationType::Contribution, 10_000_000);
    ($this->run)(PartnerOperationType::Withdrawal, 1_000_000);
    ($this->run)(PartnerOperationType::LoanFromPartner, 5_000_000);

    $statement = $this->ledger->statement($this->partner, new TableQuery(request: Request::create('/')));
    $codes = $statement->getCollection()->pluck('account.code')->sort()->values()->all();

    // A partner operation invisible on the partner's own statement would be worse
    // than no operation at all.
    expect($codes)->toBe(['2200', '3000', '3100']);
});
