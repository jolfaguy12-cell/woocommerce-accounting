<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->admin = User::factory()->create()->assignRole('admin');
    $this->partner = User::factory()->create()->assignRole('partner_viewer');
});

/**
 * The bug the picker exists to kill: every financial form built its own <select>
 * of the first 300–500 parties by name. Past that cap the party you needed was
 * simply not in the list, nothing said so, and the form quietly offered you
 * somebody else.
 */
it('finds a party that a capped dropdown would never have shown', function () {
    // 600 parties, and the one we want sorts last by name.
    foreach (range(1, 600) as $i) {
        Party::createWithRole('customer', ['name' => "شرکت {$i}"]);
    }
    Party::createWithRole('supplier', ['name' => 'یکتا پخش']);

    $this->actingAs($this->admin)
        ->getJson(route('parties.search', ['q' => 'یکتا']))
        ->assertOk()
        ->assertJsonFragment(['name' => 'یکتا پخش'])
        ->assertJsonPath('has_more', false);
});

it('searches by phone, email and national id — not just by name', function () {
    Party::createWithRole('customer', [
        'name' => 'مریم احمدی',
        'phone' => '09121112233',
        'email' => 'maryam@example.com',
        'national_id' => '0012345678',
    ]);

    foreach (['09121112233', 'maryam@example.com', '0012345678'] as $term) {
        $this->actingAs($this->admin)
            ->getJson(route('parties.search', ['q' => $term]))
            ->assertOk()
            ->assertJsonFragment(['name' => 'مریم احمدی']);
    }
});

it('filters to one business role when the form asks for one', function () {
    Party::createWithRole('supplier', ['name' => 'پخش تهران']);
    Party::createWithRole('customer', ['name' => 'مشتری تهران']);

    $response = $this->actingAs($this->admin)
        ->getJson(route('parties.search', ['q' => 'تهران', 'role' => 'supplier']))
        ->assertOk();

    expect($response->json('results'))->toHaveCount(1)
        ->and($response->json('results.0.name'))->toBe('پخش تهران');
});

it('pages on the server rather than shipping the whole table', function () {
    foreach (range(1, 45) as $i) {
        Party::createWithRole('customer', ['name' => 'شرکت آزمایشی']);
    }

    $first = $this->actingAs($this->admin)
        ->getJson(route('parties.search', ['q' => 'آزمایشی']))->assertOk();

    expect($first->json('results'))->toHaveCount(20)
        ->and($first->json('has_more'))->toBeTrue();

    $last = $this->actingAs($this->admin)
        ->getJson(route('parties.search', ['q' => 'آزمایشی', 'page' => 3]))->assertOk();

    expect($last->json('results'))->toHaveCount(5)
        ->and($last->json('has_more'))->toBeFalse();
});

it('is closed to partner viewers', function () {
    $this->actingAs($this->partner)->getJson(route('parties.search'))->assertForbidden();
});

/** The picker's route must not be swallowed by the /parties/{party} wildcard. */
it('resolves parties/search rather than treating "search" as a party id', function () {
    $this->actingAs($this->admin)->getJson(route('parties.search'))
        ->assertOk()
        ->assertJsonStructure(['results', 'has_more']);
});

it('renders the picker on every form that used to cap its party dropdown', function () {
    app(BankAccountManager::class)->create(['name' => 'بانک ملت']);

    foreach ([route('loans.create'), route('cheques.create'), route('financial-operations.create')] as $url) {
        $this->actingAs($this->admin)->get($url)
            ->assertOk()
            ->assertSee('partySelect', escape: false)
            ->assertSee(route('parties.search'), escape: false);
    }
});
