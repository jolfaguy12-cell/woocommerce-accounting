<?php

use App\Domain\Accounting\Models\Party;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->admin = User::factory()->create()->assignRole('admin');

    $this->payload = fn (array $overrides = []) => $overrides + [
        'name' => 'سارا محمدی',
        'email' => 'sara@example.com',
        'password' => 'Password!2345',
        'password_confirmation' => 'Password!2345',
        'role' => 'accountant',
        'party_mode' => 'none',
    ];
});

/** A login with no business identity is normal and must stay possible. */
it('creates a user with no party at all', function () {
    $this->actingAs($this->admin)
        ->post(route('users.store'), ($this->payload)())
        ->assertSessionHasNoErrors();

    expect(User::firstWhere('email', 'sara@example.com')->party_id)->toBeNull();
});

/**
 * The two halves of the form are different questions: «سطح دسترسی سیستم» is what
 * the login may do, «نقش‌های تجاری» is who the person is to the business. Neither
 * is inferred from the other.
 */
it('links a user to an existing party and activates the business role with its profile', function () {
    $party = Party::create(['name' => 'سارا محمدی']);

    $this->actingAs($this->admin)
        ->post(route('users.store'), ($this->payload)([
            'party_mode' => 'existing',
            'party_id' => $party->id,
            'business_roles' => ['employee'],
        ]))
        ->assertSessionHasNoErrors();

    $user = User::firstWhere('email', 'sara@example.com');
    $party->refresh();

    expect($user->party_id)->toBe($party->id)
        ->and($user->hasRole('accountant'))->toBeTrue()   // system access
        ->and($party->hasRole('employee'))->toBeTrue()    // business role
        // The role and its profile are created together — a role with no profile
        // is a label with no ledger behind it.
        ->and($party->employee)->not->toBeNull();
});

it('creates a new party when asked, and activates its role', function () {
    $this->actingAs($this->admin)
        ->post(route('users.store'), ($this->payload)([
            'party_mode' => 'new',
            'party_name' => 'رضا کریمی',
            'party_phone' => '09121112233',
            'business_roles' => ['partner'],
        ]))
        ->assertSessionHasNoErrors();

    $party = Party::firstWhere('name', 'رضا کریمی');

    expect($party)->not->toBeNull()
        ->and($party->hasRole('partner'))->toBeTrue()
        ->and($party->partnerProfile)->not->toBeNull()
        ->and(User::firstWhere('email', 'sara@example.com')->party_id)->toBe($party->id);
});

/**
 * This form is the easiest place in the system to make a second copy of someone
 * who already exists — you are typing a name and a phone from scratch for a person
 * the business already deals with.
 */
it('refuses to silently create a duplicate identity', function () {
    $existing = Party::createWithRole('supplier', ['name' => 'رضا کریمی', 'phone' => '09121112233']);

    $this->actingAs($this->admin)
        ->post(route('users.store'), ($this->payload)([
            'party_mode' => 'new',
            'party_name' => 'رضا ک',
            'party_phone' => '0912-111-2233', // same number, written differently
            'business_roles' => ['partner'],
        ]))
        ->assertSessionHasErrors('party_name');

    expect(Party::count())->toBe(1)
        ->and(Party::first()->id)->toBe($existing->id)
        // The whole request rolls back: no orphan login left behind either.
        ->and(User::where('email', 'sara@example.com')->exists())->toBeFalse();
});

it('will not grant a business role without a party to hold it', function () {
    $this->actingAs($this->admin)
        ->post(route('users.store'), ($this->payload)([
            'party_mode' => 'none',
            'business_roles' => ['employee'],
        ]))
        ->assertSessionHasErrors('party_mode');

    expect(User::where('email', 'sara@example.com')->exists())->toBeFalse();
});

it('never deletes the party when the login is deleted', function () {
    $party = Party::createWithRole('employee', ['name' => 'سارا محمدی']);
    $user = User::factory()->create(['party_id' => $party->id]);
    $user->assignRole('accountant');

    $this->actingAs($this->admin)->delete(route('users.destroy', $user))->assertSessionHasNoErrors();

    // Deleting a login does not un-hire a person, and their journal history is
    // still hanging off this party.
    expect(User::find($user->id))->toBeNull()
        ->and(Party::find($party->id))->not->toBeNull()
        ->and($party->fresh()->hasRole('employee'))->toBeTrue();
});

it('is admin-only', function () {
    $accountant = User::factory()->create()->assignRole('accountant');

    $this->actingAs($accountant)->post(route('users.store'), ($this->payload)())->assertForbidden();
});

/**
 * An edit that says nothing about the business identity is not asking to change
 * it. Defaulting a silent request to «بدون طرف حساب» would have unlinked an
 * employee from their own party — and their salary — on a password change.
 */
it('keeps an existing party link on an edit that never mentions it', function () {
    $party = Party::createWithRole('employee', ['name' => 'سارا محمدی']);
    $user = User::factory()->create(['party_id' => $party->id]);
    $user->assignRole('accountant');

    $this->actingAs($this->admin)
        ->put(route('users.update', $user), [
            'name' => 'سارا محمدی',
            'email' => $user->email,
            'role' => 'accountant',
        ])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->party_id)->toBe($party->id)
        ->and($party->fresh()->hasRole('employee'))->toBeTrue();
});
