<?php

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Models\ExpenseCategory;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Expenses\Services\ExpenseRecorder;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CostCenterSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(CostCenterSeeder::class);

    $this->bank = app(BankAccountManager::class)->create([
        'name' => 'بانک ملت اصلی',
        'bank_name' => 'ملت',
        'iban' => 'IR000000000000000000000001',
    ]);

    $this->category = ExpenseCategory::create([
        'name' => 'تبلیغات', 'slug' => 'ads', 'account_code' => '6200',
    ]);
});

it('creates a dedicated ledger account for each bank account', function () {
    expect($this->bank->account)->not->toBeNull()
        ->and($this->bank->account->type)->toBe('asset')
        ->and($this->bank->account->parent->code)->toBe('1100');

    $cash = app(BankAccountManager::class)->create(['name' => 'صندوق فروشگاه', 'is_cash' => true]);
    expect($cash->account->parent->code)->toBe('1000');
});

it('records an expense and posts a balanced journal entry', function () {
    $user = User::factory()->create();

    $expense = app(ExpenseRecorder::class)->record([
        'expense_category_id' => $this->category->id,
        'bank_account_id' => $this->bank->id,
        'amount' => 1_200_000,
        'expense_date' => Carbon::parse('2026-07-08', 'Asia/Tehran'),
        'description' => 'کمپین تیر ماه',
        'cost_center_slug' => 'marketing',
        'created_by' => $user->id,
    ]);

    $entry = $expense->journalEntry;
    expect($expense->jalali_period)->toBe($entry->jalali_period)
        ->and($entry->lines)->toHaveCount(2)
        ->and($entry->lines->firstWhere('debit', '>', 0)->account->code)->toBe('6200')
        ->and($entry->lines->firstWhere('debit', '>', 0)->costCenter->slug)->toBe('marketing')
        ->and($entry->lines->firstWhere('credit', '>', 0)->account_id)->toBe($this->bank->account_id)
        ->and($entry->lines->sum('debit'))->toBe(1_200_000);
});

it('posts capital expenses to the fixed-asset account instead of the category expense account', function () {
    $expense = app(ExpenseRecorder::class)->record([
        'expense_category_id' => $this->category->id,
        'bank_account_id' => $this->bank->id,
        'amount' => 30_000_000,
        'expense_date' => Carbon::now('Asia/Tehran'),
        'description' => 'خرید لپ‌تاپ',
        'is_capital' => true,
    ]);

    expect($expense->journalEntry->lines->firstWhere('debit', '>', 0)->account->code)->toBe('1500')
        ->and($expense->is_capital)->toBeTrue();
});

it('is idempotent per expense', function () {
    $expense = app(ExpenseRecorder::class)->record([
        'expense_category_id' => $this->category->id,
        'bank_account_id' => $this->bank->id,
        'amount' => 500_000,
        'expense_date' => Carbon::now('Asia/Tehran'),
        'description' => 'تکراری',
    ]);

    $count = JournalEntry::count();
    app(ExpenseRecorder::class)->postJournal($expense->refresh());

    expect(JournalEntry::count())->toBe($count)
        ->and(Expense::count())->toBe(1);
});

it('marks whether an expense affects partner profit', function () {
    $expense = app(ExpenseRecorder::class)->record([
        'expense_category_id' => $this->category->id,
        'bank_account_id' => $this->bank->id,
        'amount' => 100_000,
        'expense_date' => Carbon::now('Asia/Tehran'),
        'description' => 'شخصی',
        'affects_partner_profit' => false,
    ]);

    expect($expense->affects_partner_profit)->toBeFalse();
});
