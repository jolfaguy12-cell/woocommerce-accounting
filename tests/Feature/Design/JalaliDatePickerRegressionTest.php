<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Receivables\Models\Employee;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

beforeEach(function () {
    // <x-form.jalali-date> reads $errors, which only exists automatically inside
    // a real request lifecycle — Blade::render() in isolation needs it shared by hand.
    View::share('errors', new ViewErrorBag(['default' => new MessageBag]));
});

/**
 * `<x-form.jalali-date>`/`<x-form.jalali-date-range>` used to run a Gregorian
 * "Y-m-d" string through `Jalalian::fromFormat()`, which parses its input AS
 * Jalali — every prefilled date field in the app (purchases, expenses, loans,
 * cheques, payroll, salary payments) displayed a wrong date and opened the
 * calendar on the wrong month. Fixed to `Jalalian::fromCarbon(Carbon::parse())`.
 *
 * These tests pin the exact bug down at the component level (so it cannot
 * regress silently) and re-check it at the page level across every affected
 * module, using 2026-07-14 = 1405/04/23 as the known-good conversion.
 */
it('converts a Gregorian value to the correct Jalali display, not a garbled one', function () {
    $html = Blade::render(
        '<x-form.jalali-date name="d" :value="$value" />',
        ['value' => '2026-07-14']
    );

    expect($html)->toContain('value="1405/04/23"')
        ->and($html)->toContain('value="2026-07-14"') // the hidden field: unchanged Gregorian, the only thing submitted
        ->not->toContain('2647'); // the exact garbage year the old fromFormat() bug produced
});

it('leaves the hidden Gregorian field untouched when no value is given', function () {
    $html = Blade::render('<x-form.jalali-date name="d" />');

    expect($html)->toContain('value=""')->and($html)->not->toContain('2647');
});

it('applies the same fix to the date-range component', function () {
    $html = Blade::render(
        '<x-form.jalali-date-range from-name="a" to-name="b" :from-value="$f" :to-value="$t" />',
        ['f' => '2026-07-14', 't' => '2026-07-20']
    );

    expect($html)->toContain('value="1405/04/23"')
        ->and($html)->toContain('value="1405/04/29"');
});

/*
|--------------------------------------------------------------------------
| Page-level sweep: every module that pre-fills a date through the shared
| component must show today as «۱۴۰۵/۰۴/۲۳», not a garbled year.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-14 10:00:00', 'Asia/Tehran'));

    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('shows the correct Jalali "today" on the expense form', function () {
    $this->actingAs($this->admin)->get(route('fast-forms'))
        ->assertOk()
        ->assertSee('1405/04/23')
        ->assertDontSee('2647');
});

it('shows the correct Jalali "today" on the loan form', function () {
    $this->actingAs($this->admin)->get(route('loans.create'))
        ->assertOk()
        ->assertSee('1405/04/23')
        ->assertDontSee('2647');
});

it('shows the correct Jalali "today" on the cheque form', function () {
    $this->actingAs($this->admin)->get(route('cheques.create'))
        ->assertOk()
        ->assertSee('1405/04/23')
        ->assertDontSee('2647');
});

it('shows the correct Jalali "today" on the payroll accrual form', function () {
    // The «پرداخت هم‌زمان» date field only renders inside an employee row.
    Party::createWithRole('employee', ['name' => 'سارا محمدی']);

    $this->actingAs($this->admin)->get(route('payroll.create'))
        ->assertOk()
        ->assertSee('1405/04/23')
        ->assertDontSee('2647');
});

it('shows the correct Jalali hired-at date on the employee page', function () {
    $party = Party::createWithRole('employee', ['name' => 'سارا محمدی']);
    Employee::firstWhere('party_id', $party->id)
        ->update(['hired_at' => '2026-07-14']);

    $this->actingAs($this->admin)->get(route('employees.show', $party))
        ->assertOk()
        ->assertSee('1405/04/23')
        ->assertDontSee('2647');
});
