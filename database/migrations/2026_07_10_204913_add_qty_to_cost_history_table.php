<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cost_history', function (Blueprint $table) {
            // Display-only context for manual entries (e.g. "bought 10 units at
            // this price") — never drives any calculation or accounting document.
            // Real purchase quantities/totals live on purchase_invoice_lines.
            $table->unsignedInteger('qty')->nullable()->after('unit_cost');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cost_history', function (Blueprint $table) {
            $table->dropColumn('qty');
        });
    }
};
