<?php

use App\Domain\Accounting\Models\Account;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Account::firstOrCreate(['code' => '6400'], [
            'name' => 'مطالبات سوخت‌شده',
            'type' => 'expense',
            'is_system' => true,
        ]);
    }

    public function down(): void
    {
        Account::where('code', '6400')->whereDoesntHave('lines')->delete();
    }
};
