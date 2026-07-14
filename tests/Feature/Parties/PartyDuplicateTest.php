<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\PartyDuplicateService;
use App\Domain\Accounting\Support\PartyIdentityBackfill;
use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->service = app(PartyDuplicateService::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

it('suggests a duplicate across roles when a strong identifier matches', function () {
    // The exact case the single-role model could never see: the same company,
    // once as a customer and once as a supplier.
    Party::createWithRole('customer', ['name' => 'شرکت الف', 'company_national_id' => '10101010101']);
    Party::createWithRole('supplier', ['name' => 'شرکت الف (پخش)', 'company_national_id' => '10101010101']);

    $groups = $this->service->candidates();

    expect($groups)->toHaveCount(1)
        ->and($groups->first()['strength'])->toBe('strong')
        ->and($groups->first()['parties'])->toHaveCount(2);
});

it('ranks a shared phone as a weak signal, not a strong one', function () {
    Party::createWithRole('customer', ['name' => 'رضا', 'phone' => '09121234567']);
    Party::createWithRole('customer', ['name' => 'مریم', 'phone' => '09121234567']);

    PartyIdentityBackfill::normalizedPhones();

    $groups = $this->service->candidates();

    expect($groups)->toHaveCount(1)
        ->and($groups->first()['strength'])->toBe('weak')
        ->and($groups->first()['reason'])->toBe('شماره تماس یکسان');
});

it('never suggests a duplicate on name alone', function () {
    Party::createWithRole('customer', ['name' => 'علی محمدی']);
    Party::createWithRole('customer', ['name' => 'علی محمدی']);

    expect($this->service->candidates())->toBeEmpty();
});

it('lists per-party matches without merging anything', function () {
    $a = Party::createWithRole('customer', ['name' => 'شرکت ب', 'national_id' => '1234567890']);
    $b = Party::createWithRole('supplier', ['name' => 'شرکت ب دوم', 'national_id' => '1234567890']);

    $matches = $this->service->matchesFor($a);

    expect($matches)->toHaveCount(1)
        ->and($matches->first()['party']->id)->toBe($b->id)
        ->and($matches->first()['strength'])->toBe('strong')
        // Detection only: both parties still exist, unchanged and unmerged.
        ->and(Party::count())->toBe(2)
        ->and($b->fresh()->hasRole('supplier'))->toBeTrue()
        ->and($b->fresh()->hasRole('customer'))->toBeFalse();
});

/**
 * The review page is now ACTIONABLE — but the thing that made merging safe to
 * offer has not changed: detection still merges nothing on its own. An admin
 * gets a merge button and must give a reason; everyone else gets the same
 * read-only suggestions they always had.
 */
it('renders the duplicate review page with a merge action for an admin', function () {
    Party::createWithRole('customer', ['name' => 'شرکت ج', 'tax_id' => '99887766']);
    Party::createWithRole('supplier', ['name' => 'شرکت ج پخش', 'tax_id' => '99887766']);

    $this->actingAs($this->admin)->get(route('parties.duplicates'))
        ->assertOk()
        ->assertSee('شناسه مالیاتی یکسان')
        ->assertSee('هیچ ادغام خودکاری انجام نمی‌شود', false)
        ->assertSee('ادغام طرف حساب‌ها')
        ->assertSee('دلیل ادغام');

    // Still detection only: looking at the page merges nothing.
    expect(Party::whereNotNull('merged_into_id')->count())->toBe(0);
});

it('offers no merge action to a non-admin', function () {
    $accountant = User::factory()->create()->assignRole('accountant');

    Party::createWithRole('customer', ['name' => 'شرکت ج', 'tax_id' => '99887766']);
    Party::createWithRole('supplier', ['name' => 'شرکت ج پخش', 'tax_id' => '99887766']);

    $this->actingAs($accountant)->get(route('parties.duplicates'))
        ->assertOk()
        ->assertSee('شناسه مالیاتی یکسان')
        ->assertDontSee('دلیل ادغام');
});
