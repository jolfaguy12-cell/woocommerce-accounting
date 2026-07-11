<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_dashboard_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('jalali_period', 7)->unique();
            $table->unsignedInteger('new_customers');
            $table->unsignedInteger('orders_count');
            $table->unsignedBigInteger('gross_sales');
            // Null until the first month this feature has been live for has fully closed —
            // there is no historical record of past stock levels to snapshot retroactively.
            $table->unsignedInteger('stock_count')->nullable();
            $table->timestamp('computed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_dashboard_snapshots');
    }
};
