<?php

use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Models\ExpenseCategory;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Expenses\Services\ExpenseRecorder;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CostCenterSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class, CostCenterSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->warehouse = User::factory()->create()->assignRole('warehouse');
});

it('creates a bank account with a dedicated ledger account', function () {
    $this->actingAs($this->admin)->post('/bank-accounts', [
        'name' => 'بانک ملت اصلی',
        'bank_name' => 'ملت',
        'card_number' => '6104337812345678',
        'iban' => 'IR000000000000000000000001',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $account = BankAccount::firstWhere('name', 'بانک ملت اصلی');

    expect($account)->not->toBeNull()
        ->and($account->card_number)->toBe('6104337812345678')
        ->and($account->account->type)->toBe('asset');

    // Warehouse users can view products/orders but never mutate financial data.
    $this->actingAs($this->warehouse)->post('/bank-accounts', ['name' => 'x'])->assertForbidden();
});

it('lists bank accounts with their computed balance', function () {
    $bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت اصلی', 'bank_name' => 'ملت']);
    $category = ExpenseCategory::create(['name' => 'تبلیغات', 'slug' => 'ads', 'account_code' => '6200']);

    app(ExpenseRecorder::class)->record([
        'expense_category_id' => $category->id,
        'bank_account_id' => $bank->id,
        'amount' => 50_000,
        'description' => 'تست',
        'expense_date' => now(),
        'created_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)->get('/bank-accounts')
        ->assertOk()
        ->assertViewHas('accounts', fn ($accounts) => $accounts->first()['balance'] === -50_000);
});

it('opens the create modal automatically on /new-bank-account', function () {
    $this->actingAs($this->admin)->get('/new-bank-account')
        ->assertOk()
        ->assertViewHas('openCreate', true);

    $this->actingAs($this->admin)->get('/bank-accounts')
        ->assertOk()
        ->assertViewHas('openCreate', false);
});

it('shows transaction history and balance for a single bank account', function () {
    $bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت اصلی', 'bank_name' => 'ملت']);
    $category = ExpenseCategory::create(['name' => 'تبلیغات', 'slug' => 'ads', 'account_code' => '6200']);

    app(ExpenseRecorder::class)->record([
        'expense_category_id' => $category->id,
        'bank_account_id' => $bank->id,
        'amount' => 75_000,
        'description' => 'هزینه تبلیغات',
        'expense_date' => now(),
        'created_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)->get("/bank-accounts/{$bank->id}")
        ->assertOk()
        ->assertViewHas('balance', -75_000)
        ->assertViewHas('transactions', fn ($transactions) => $transactions->count() === 1
            && $transactions->first()->credit === 75_000);

    expect(Expense::where('bank_account_id', $bank->id)->count())->toBe(1);
});
