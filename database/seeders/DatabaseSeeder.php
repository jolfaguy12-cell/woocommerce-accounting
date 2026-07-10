<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            ChartOfAccountsSeeder::class,
            CostCenterSeeder::class,
            ExpenseCategorySeeder::class,
            ChannelSeeder::class,
            AlertTypeSeeder::class,
        ]);
    }
}
