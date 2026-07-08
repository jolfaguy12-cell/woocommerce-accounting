<?php

use App\Models\User;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(ChannelSeeder::class);
});

it('redirects guests to login', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('shows full financial KPIs to admins', function () {
    $admin = User::factory()->create()->assignRole('admin');

    $this->actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(
        fn (Assert $page) => $page
            ->component('dashboard')
            ->where('dashboard.can_see_financials', true)
            ->has('dashboard.financials.kpis')
            ->has('dashboard.financials.trend')
            ->has('dashboard.operations.review')
            ->has('dashboard.operations.sync'),
    );
});

it('hides financial data from warehouse users but keeps operational panels', function () {
    $warehouse = User::factory()->create()->assignRole('warehouse');

    $this->actingAs($warehouse)->get('/dashboard')->assertOk()->assertInertia(
        fn (Assert $page) => $page
            ->component('dashboard')
            ->where('dashboard.can_see_financials', false)
            ->where('dashboard.financials', null)
            ->has('dashboard.operations.review'),
    );
});

it('lets partner viewers see financials', function () {
    $partner = User::factory()->create()->assignRole('partner_viewer');

    $this->actingAs($partner)->get('/dashboard')->assertOk()->assertInertia(
        fn (Assert $page) => $page->where('dashboard.can_see_financials', true),
    );
});
