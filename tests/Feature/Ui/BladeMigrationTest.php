<?php

use App\Models\User;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CostCenterSeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\RoleSeeder;

/*
 * Guards the Blade + Alpine migration: every previously-React/Inertia screen
 * must now render a real Blade view. assertViewIs() only passes for a Blade
 * response, so these fail loudly if anything regresses to Inertia.
 */

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class, ChannelSeeder::class, CostCenterSeeder::class, ExpenseCategorySeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
});

it('renders the guest auth screens as Blade views', function (string $url, string $view) {
    $this->get($url)->assertOk()->assertViewIs($view);
})->with([
    ['/login', 'pages.auth.login'],
    ['/forgot-password', 'pages.auth.forgot-password'],
    ['/reset-password/fake-token', 'pages.auth.reset-password'],
]);

it('renders the authenticated auth screens as Blade views', function () {
    $this->actingAs($this->admin)->get('/confirm-password')->assertOk()->assertViewIs('pages.auth.confirm-password');
});

it('renders every migrated admin screen as a Blade view', function (string $url, string $view) {
    $this->actingAs($this->admin)->get($url)->assertOk()->assertViewIs($view);
})->with([
    ['/review', 'pages.review.index'],
    ['/fast-forms', 'pages.fast-forms.index'],
    ['/users', 'pages.users.index'],
    ['/settings/password', 'pages.settings.password'],
    ['/settings/appearance', 'pages.settings.appearance'],
    ['/settings/profile', 'pages.settings.profile'],
    ['/setting', 'pages.system-settings.general'],
    ['/setting/report-settings', 'pages.system-settings.report-settings'],
    ['/setting/role-managment', 'pages.system-settings.role-management'],
    ['/setting/api-webhook-managment', 'pages.system-settings.api-webhook-management'],
]);

it('still creates, updates and deletes users from the Blade screen', function () {
    $this->actingAs($this->admin)->post('/users', [
        'name' => 'حسابدار تست',
        'email' => 'acc@test.local',
        'password' => 'Password!2345',
        'password_confirmation' => 'Password!2345',
        'role' => 'accountant',
    ])->assertRedirect()->assertSessionHas('success');

    $user = User::firstWhere('email', 'acc@test.local');
    expect($user->hasRole('accountant'))->toBeTrue();

    $this->actingAs($this->admin)->put("/users/{$user->id}", [
        'name' => 'انباردار تست',
        'email' => 'acc@test.local',
        'role' => 'warehouse',
    ])->assertRedirect()->assertSessionHas('success');

    expect($user->refresh()->hasRole('warehouse'))->toBeTrue()
        ->and($user->name)->toBe('انباردار تست');

    $this->actingAs($this->admin)->delete("/users/{$user->id}")->assertRedirect();
    expect(User::find($user->id))->toBeNull();
});

it('keeps the last-admin guard rail on the Blade users screen', function () {
    // Demoting the only admin must be refused (error surfaced via session errors).
    $this->actingAs($this->admin)->put("/users/{$this->admin->id}", [
        'name' => $this->admin->name,
        'email' => $this->admin->email,
        'role' => 'accountant',
    ])->assertSessionHasErrors('role');

    expect($this->admin->refresh()->hasRole('admin'))->toBeTrue();
});

it('updates the password from the Blade settings screen', function () {
    $user = User::factory()->create(['password' => bcrypt('OldPassword!1')])->assignRole('accountant');

    $this->actingAs($user)->put('/settings/password', [
        'current_password' => 'OldPassword!1',
        'password' => 'NewPassword!2345',
        'password_confirmation' => 'NewPassword!2345',
    ])->assertRedirect()->assertSessionHas('status', 'password-updated');

    expect(Hash::check('NewPassword!2345', $user->refresh()->password))->toBeTrue();
});
