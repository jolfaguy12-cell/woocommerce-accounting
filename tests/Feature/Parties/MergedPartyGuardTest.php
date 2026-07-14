<?php

use App\Domain\Accounting\Exceptions\MergedPartyException;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Services\PartyMergeService;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Expenses\Models\ExpenseCategory;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Expenses\Services\ExpenseRecorder;
use App\Domain\Expenses\Support\ExpenseFundingSource;
use App\Domain\Orders\Services\CustomerResolver;
use App\Domain\Receivables\Models\Employee;
use App\Domain\Receivables\Services\PaymentRecorder;
use App\Domain\Receivables\Services\PayrollService;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/**
 * A merged party keeps its id and every journal line ever posted against it — that
 * is the whole alias design, and it is why its history can still be read.
 *
 * What it must never do is receive a NEW transaction. The identity is not live, no
 * screen lists it, and a balance accumulating there is a balance nobody would ever
 * see: the duplicate would come back to life one transaction at a time.
 *
 * Two mechanisms, and both are tested here:
 *   1. Every posting service RESOLVES Party::canonical() first, so a payment aimed
 *      at an absorbed id simply lands on the survivor.
 *   2. JournalPoster REFUSES a merged party_id outright — the backstop for whatever
 *      forgets to resolve. It fails loudly rather than silently rewriting the id:
 *      an entry posted to somebody other than the party the caller named is worse
 *      than an entry that did not post at all.
 */
beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->admin = User::factory()->create()->assignRole('admin');
    $this->merges = app(PartyMergeService::class);
    $this->ledger = app(PartyLedgerService::class);
    $this->payments = app(PaymentRecorder::class);

    $this->survivor = Party::createWithRole('customer', ['name' => 'مریم احمدی', 'phone' => '09121112233']);
    $this->duplicate = Party::createWithRole('customer', ['name' => 'مریم احمدی', 'phone' => '09121112233']);

    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);
});

/*
|--------------------------------------------------------------------------
| 1. The absorbed identity cannot receive new money
|--------------------------------------------------------------------------
*/

it('refuses to post a new journal entry against a merged party', function () {
    $this->merges->merge($this->survivor, $this->duplicate, 'ادغام', $this->admin);

    expect(fn () => app(JournalPoster::class)->post([
        'entry_date' => Carbon::parse('2026-07-10', 'Asia/Tehran'),
        'description' => 'فروش جدید به پرونده ادغام‌شده',
        'idempotency_key' => 'test:new-on-merged',
    ], [
        ['account' => AccountCode::AccountsReceivable, 'debit' => 500_000, 'party_id' => $this->duplicate->id],
        ['account' => AccountCode::SalesRevenue, 'credit' => 500_000],
    ]))->toThrow(MergedPartyException::class);

    expect(JournalLine::where('party_id', $this->duplicate->id)->count())->toBe(0);
});

/**
 * The one entry that legitimately names an absorbed party: undoing a mistake that
 * was made against that id, before the merge, and must be undone against that id.
 */
it('still allows a REVERSAL of an entry posted against the party before it was merged', function () {
    $entry = app(JournalPoster::class)->post([
        'entry_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'description' => 'فروش',
        'idempotency_key' => 'test:pre-merge',
    ], [
        ['account' => AccountCode::AccountsReceivable, 'debit' => 400_000, 'party_id' => $this->duplicate->id],
        ['account' => AccountCode::SalesRevenue, 'credit' => 400_000],
    ]);

    $this->merges->merge($this->survivor, $this->duplicate, 'ادغام', $this->admin);

    $reversal = app(JournalPoster::class)->reverse($entry, 'فاکتور اشتباه بود', $this->admin->id);

    expect($reversal->lines->firstWhere('party_id', $this->duplicate->id))->not->toBeNull()
        // …and the survivor's balance nets to zero, because it sums over identityIds().
        ->and($this->ledger->customerReceivable($this->survivor->fresh()))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| 2. Posting services resolve the canonical party first
|--------------------------------------------------------------------------
*/

it('lands a payment aimed at the absorbed party on the survivor', function () {
    $this->merges->merge($this->survivor, $this->duplicate, 'ادغام', $this->admin);

    $payment = $this->payments->receive($this->duplicate->fresh(), 300_000, $this->bank->id);

    expect($payment->party_id)->toBe($this->survivor->id);

    // Not one new line names the absorbed id.
    expect(JournalLine::where('journal_entry_id', $payment->journal_entry_id)
        ->where('party_id', $this->duplicate->id)->count())->toBe(0);
});

it('lands an expense funded by the absorbed party on the survivor', function () {
    $employee = Party::createWithRole('employee', ['name' => 'سارا']);
    $employeeDuplicate = Party::createWithRole('employee', ['name' => 'سارا محمدی']);

    $this->merges->merge($employee, $employeeDuplicate, 'یک نفر', $this->admin);

    $category = ExpenseCategory::create([
        'name' => 'اداری', 'slug' => 'admin',
        'account_code' => AccountCode::OperatingExpense->value, 'is_active' => true,
    ]);

    $expense = app(ExpenseRecorder::class)->record([
        'expense_category_id' => $category->id,
        'funding_source' => ExpenseFundingSource::Employee->value,
        'funded_by_party_id' => $employeeDuplicate->id, // the dead id
        'amount' => 800_000,
        'description' => 'تاکسی',
        'expense_date' => Carbon::parse('2026-07-05', 'Asia/Tehran'),
    ]);

    expect($expense->funded_by_party_id)->toBe($employee->id)
        ->and($this->ledger->employeePaidExpenses($employee->fresh()))->toBe(800_000);
});

it('accrues payroll for a merged employee against the surviving identity', function () {
    $employee = Party::createWithRole('employee', ['name' => 'سارا']);
    $duplicateEmployee = Party::createWithRole('employee', ['name' => 'سارا م.']);

    // The absorbed party's employee profile follows the identity (moveOrphanedProfiles),
    // but the payroll item still points at whichever Employee row is used — and its
    // journal line must name the SURVIVOR.
    $employeeRow = Employee::firstWhere('party_id', $duplicateEmployee->id);

    $this->merges->merge($employee, $duplicateEmployee, 'یک نفر', $this->admin);

    $run = app(PayrollService::class)->post('1405-04', [
        ['employee_id' => $employeeRow->id, 'gross' => 10_000_000],
    ], $this->admin->id);

    $payable = $run->journalEntry->lines->firstWhere('account_id', AccountCode::PayrollPayable->account()->id);

    expect($payable->party_id)->toBe($employee->id)
        ->and($this->ledger->payrollPayable($employee->fresh()))->toBe(10_000_000);
});

/*
|--------------------------------------------------------------------------
| 3. WooCommerce sync never resurrects the absorbed identity
|--------------------------------------------------------------------------
*/

it('resolves a synced order onto the survivor and never recreates the absorbed party', function () {
    $this->duplicate->update(['hub_customer_id' => 501]);

    $this->merges->merge($this->survivor, $this->duplicate, 'ادغام', $this->admin);

    $before = Party::count();

    $resolved = app(CustomerResolver::class)->resolve([
        'customer_id' => 0,
        'billing' => ['first_name' => 'مریم', 'last_name' => 'احمدی', 'phone' => '09121112233'],
    ]);

    expect($resolved)->toBe($this->survivor->id)
        ->and(Party::count())->toBe($before)                    // no new party minted
        ->and($this->duplicate->fresh()->merged_into_id)->toBe($this->survivor->id); // still dead
});

/**
 * The re-sync case: an order that was linked to the absorbed party before the merge
 * is resynced. It must follow the identity to the survivor — not resurrect the id it
 * happens to still be pointing at.
 */
it('follows the merge chain when an existing order is re-normalized', function () {
    $phoneless = Party::createWithRole('customer', ['name' => 'ملیکا خلیلی']);
    $survivor = Party::createWithRole('customer', ['name' => 'ملیکا خلیلی', 'phone' => '09120001122']);

    $this->merges->merge($survivor, $phoneless, 'یک نفر', $this->admin);

    $resolved = app(CustomerResolver::class)->resolve([
        'customer_id' => 0,
        'billing' => ['first_name' => 'ملیکا', 'last_name' => 'خلیلی', 'phone' => '09120001122'],
    ], existingPartyId: $phoneless->id);

    expect($resolved)->toBe($survivor->id);
});

it('never lists or offers an absorbed party, and redirects its employee page', function () {
    $employee = Party::createWithRole('employee', ['name' => 'سارا']);
    $duplicateEmployee = Party::createWithRole('employee', ['name' => 'سارا م.']);

    $this->merges->merge($employee, $duplicateEmployee, 'یک نفر', $this->admin);

    // Not in the roster…
    $this->actingAs($this->admin)
        ->get(route('employees.index'))
        ->assertOk()
        ->assertSee('سارا')
        ->assertDontSee('سارا م.');

    // …not in the picker…
    $this->actingAs($this->admin)
        ->getJson(route('parties.search', ['q' => 'سارا']))
        ->assertOk()
        ->assertJsonMissing(['id' => $duplicateEmployee->id]);

    // …and its URL is not a dead end, it is the survivor.
    $this->actingAs($this->admin)
        ->get(route('employees.show', $duplicateEmployee))
        ->assertRedirect(route('employees.show', $employee));
});

/** The invariant behind all of it: a merge never rewrites a posted line. */
it('leaves every historical journal line pointing exactly where it was posted', function () {
    app(JournalPoster::class)->post([
        'entry_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'description' => 'فروش',
        'idempotency_key' => 'test:history',
    ], [
        ['account' => AccountCode::AccountsReceivable, 'debit' => 400_000, 'party_id' => $this->duplicate->id],
        ['account' => AccountCode::SalesRevenue, 'credit' => 400_000],
    ]);

    $before = JournalLine::orderBy('id')->get(['id', 'party_id', 'debit', 'credit'])->toArray();

    $this->merges->merge($this->survivor, $this->duplicate, 'ادغام', $this->admin);

    expect(JournalLine::orderBy('id')->get(['id', 'party_id', 'debit', 'credit'])->toArray())->toBe($before)
        // …while the survivor's balance is nonetheless the whole story.
        ->and($this->ledger->customerReceivable($this->survivor->fresh()))->toBe(400_000);
});
