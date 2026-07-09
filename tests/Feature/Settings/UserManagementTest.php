<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->accountant = User::factory()->create()->assignRole('accountant');
});

it('lets an admin create a user with a role', function () {
    $this->actingAs($this->admin)->post('/users', [
        'name' => 'حسابدار جدید',
        'email' => 'new@example.com',
        'password' => 'secret-password-123',
        'password_confirmation' => 'secret-password-123',
        'role' => 'accountant',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $user = User::firstWhere('email', 'new@example.com');
    expect($user)->not->toBeNull()
        ->and($user->hasRole('accountant'))->toBeTrue();
});

it('blocks non-admins from user management', function () {
    $this->actingAs($this->accountant)->get('/users')->assertForbidden();

    $this->actingAs($this->accountant)->post('/users', [
        'name' => 'x', 'email' => 'x@example.com',
        'password' => 'secret-password-123', 'password_confirmation' => 'secret-password-123',
        'role' => 'admin',
    ])->assertForbidden();

    expect(User::firstWhere('email', 'x@example.com'))->toBeNull();
});

it('updates a user role and password from the admin panel', function () {
    $this->actingAs($this->admin)->put("/users/{$this->accountant->id}", [
        'name' => $this->accountant->name,
        'email' => $this->accountant->email,
        'password' => 'brand-new-password-1',
        'password_confirmation' => 'brand-new-password-1',
        'role' => 'warehouse',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($this->accountant->refresh()->hasRole('warehouse'))->toBeTrue()
        ->and($this->accountant->hasRole('accountant'))->toBeFalse();
});

it('never demotes or deletes the last admin', function () {
    $this->actingAs($this->admin)->put("/users/{$this->admin->id}", [
        'name' => $this->admin->name,
        'email' => $this->admin->email,
        'password' => '',
        'password_confirmation' => '',
        'role' => 'accountant',
    ])->assertSessionHasErrors('role');

    expect($this->admin->refresh()->hasRole('admin'))->toBeTrue();

    // Self-deletion is blocked too.
    $this->actingAs($this->admin)->delete("/users/{$this->admin->id}")->assertSessionHasErrors('user');
    expect($this->admin->fresh())->not->toBeNull();
});

it('lets an admin delete another user', function () {
    $this->actingAs($this->admin)->delete("/users/{$this->accountant->id}")
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($this->accountant->fresh())->toBeNull();
});
