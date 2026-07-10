<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_profits', function (Blueprint $table) {
            // Resolved once at calculation time (manual override / weight tier / flat
            // default) and frozen here — later settings/tier edits never change past
            // orders unless the order is explicitly recalculated. Not journal-posted yet.
            $table->unsignedInteger('package_weight_grams')->nullable()->after('channel_fee_source');
            $table->unsignedBigInteger('packaging_cost')->nullable()->after('package_weight_grams');
            $table->string('packaging_cost_basis', 20)->nullable()->after('packaging_cost'); // manual / tier / default
        });
    }

    public function down(): void
    {
        Schema::table('order_profits', function (Blueprint $table) {
            $table->dropColumn(['package_weight_grams', 'packaging_cost', 'packaging_cost_basis']);
        });
    }
};
