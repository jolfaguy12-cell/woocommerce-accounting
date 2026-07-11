<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Shipping address preferred over billing (where the order actually
            // goes), falling back to billing when no separate shipping address
            // was given — see OrderNormalizer.
            $table->string('city')->nullable()->after('payment_method_title')->index();
            $table->string('province')->nullable()->after('city')->index();
            $table->string('shipping_method_title')->nullable()->after('province');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['city', 'province', 'shipping_method_title']);
        });
    }
};
