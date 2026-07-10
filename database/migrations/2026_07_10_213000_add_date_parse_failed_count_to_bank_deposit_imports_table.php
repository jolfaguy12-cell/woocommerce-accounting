<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_deposit_imports', function (Blueprint $table) {
            $table->unsignedInteger('date_parse_failed_count')->default(0)->after('new_bank_accounts_count');
        });
    }

    public function down(): void
    {
        Schema::table('bank_deposit_imports', function (Blueprint $table) {
            $table->dropColumn('date_parse_failed_count');
        });
    }
};
