<?php

use App\Models\User;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(ChannelSeeder::class);
});

it('redirects guests to login', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

// The dashboard currently serves the static TailAdmin template (RTL, IRANSansX)
// while it is being customized; backend wiring will return via DashboardController.
it('renders the TailAdmin dashboard for every role', function () {
    foreach (['admin', 'warehouse', 'partner_viewer'] as $role) {
        $user = User::factory()->create()->assignRole($role);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertViewIs('pages.dashboard.ecommerce')
            ->assertSee('dir="rtl"', false);
    }
});

it('serves the TailAdmin demo pages behind auth', function () {
    $paths = ['/calendar', '/form-elements', '/basic-tables', '/blank'];

    foreach ($paths as $path) {
        $this->get($path)->assertRedirect('/login');
    }

    $this->actingAs(User::factory()->create()->assignRole('admin'));
    foreach ($paths as $path) {
        $this->get($path)->assertOk();
    }
});
