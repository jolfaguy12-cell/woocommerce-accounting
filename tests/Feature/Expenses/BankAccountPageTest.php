<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Models\ExpenseCategory;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Expenses\Services\ExpenseRecorder;
use App\Domain\Receivables\Services\PaymentRecorder;
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

it('updates a bank account\'s display fields but never touches is_cash', function () {
    $bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت اصلی', 'bank_name' => 'ملت']);

    $this->actingAs($this->admin)
        ->put("/bank-accounts/{$bank->id}", [
            'name' => 'بانک ملت به‌روزشده',
            'bank_name' => 'ملت',
            'card_number' => '6104337800000000',
            'iban' => 'IR000000000000000000000002',
        ])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($bank->fresh()->name)->toBe('بانک ملت به‌روزشده')
        ->and($bank->fresh()->card_number)->toBe('6104337800000000')
        ->and($bank->fresh()->account->name)->toBe('بانک ملت به‌روزشده')
        ->and($bank->fresh()->is_cash)->toBeFalse();

    $this->actingAs($this->warehouse)->put("/bank-accounts/{$bank->id}", ['name' => 'x'])->assertForbidden();
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

it('filters bank account transactions by search and shows a running balance', function () {
    $bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت اصلی']);
    $category = ExpenseCategory::create(['name' => 'تبلیغات', 'slug' => 'ads', 'account_code' => '6200']);

    app(ExpenseRecorder::class)->record([
        'expense_category_id' => $category->id, 'bank_account_id' => $bank->id,
        'amount' => 30_000, 'description' => 'تبلیغات اینستاگرام', 'expense_date' => now(), 'created_by' => $this->admin->id,
    ]);
    app(ExpenseRecorder::class)->record([
        'expense_category_id' => $category->id, 'bank_account_id' => $bank->id,
        'amount' => 20_000, 'description' => 'چاپ بنر', 'expense_date' => now(), 'created_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)->get("/bank-accounts/{$bank->id}?search=".urlencode('اینستاگرام'))
        ->assertOk()
        ->assertViewHas('transactions', fn ($t) => $t->count() === 1)
        ->assertSee('تبلیغات اینستاگرام')
        ->assertDontSee('چاپ بنر');

    $this->actingAs($this->admin)->get("/bank-accounts/{$bank->id}")
        ->assertOk()
        ->assertViewHas('transactions', fn ($t) => $t->first()->balance_after === -50_000);
});

it('links a transaction\'s party to their customer profile when the party is a customer', function () {
    $bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت اصلی']);
    $party = Party::create(['type' => 'customer', 'name' => 'مشتری تست']);

    app(PaymentRecorder::class)->receiveForCustomer($party, 100_000, $bank->id);

    $this->actingAs($this->admin)->get("/bank-accounts/{$bank->id}")
        ->assertOk()
        ->assertSee(route('customers.show', $party));
});
