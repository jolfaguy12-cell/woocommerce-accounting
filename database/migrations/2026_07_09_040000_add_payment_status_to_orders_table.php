<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Derived from the hub's date_paid: paid when set, unpaid otherwise.
            $table->enum('payment_status', ['paid', 'unpaid'])->default('unpaid')->after('payment_method_title')->index();
            $table->timestamp('date_paid')->nullable()->after('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'date_paid']);
        });
    }
};
