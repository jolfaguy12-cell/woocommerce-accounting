<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_mirror', function (Blueprint $table) {
            // Grams; the hub's WooCommerce weight unit is confirmed grams for this store.
            $table->unsignedInteger('weight_grams')->nullable()->after('stock_status');
        });
    }

    public function down(): void
    {
        Schema::table('product_mirror', function (Blueprint $table) {
            $table->dropColumn('weight_grams');
        });
    }
};
