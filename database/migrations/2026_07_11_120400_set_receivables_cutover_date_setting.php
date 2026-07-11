<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Orders dated before this never get receivables-tracked (see
     * CreditOrderSync), no matter what happens to them later — confirmed
     * with the user as today's date at the time this feature shipped.
     */
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(['key' => 'receivables_cutover_date'], [
            'value' => json_encode('2026-07-11'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'receivables_cutover_date')->delete();
    }
};
