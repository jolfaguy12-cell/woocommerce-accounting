<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packaging_cost_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('min_weight_grams')->unique(); // package weight >= this triggers the tier
            $table->unsignedBigInteger('cost'); // Toman
            $table->timestamps();
        });

        Schema::create('order_packaging_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('real_cost'); // Toman, manual override
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_packaging_costs');
        Schema::dropIfExists('packaging_cost_tiers');
    }
};
