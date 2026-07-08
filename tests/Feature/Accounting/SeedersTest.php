<?php

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\CostCenter;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CostCenterSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;

it('seeds the four base roles', function () {
    $this->seed(RoleSeeder::class);

    expect(Role::pluck('name')->sort()->values()->all())
        ->toBe(['accountant', 'admin', 'partner_viewer', 'warehouse']);
});

it('seeds a system chart of accounts idempotently', function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $count = Account::count();
    $this->seed(ChartOfAccountsSeeder::class);

    expect(Account::count())->toBe($count)
        ->and(Account::where('code', '4000')->value('type'))->toBe('revenue')
        ->and(Account::where('is_system', true)->count())->toBeGreaterThan(10);
});

it('seeds default cost centers idempotently', function () {
    $this->seed(CostCenterSeeder::class);
    $count = CostCenter::count();
    $this->seed(CostCenterSeeder::class);

    expect(CostCenter::count())->toBe($count)
        ->and(CostCenter::where('slug', 'warehouse')->exists())->toBeTrue();
});
