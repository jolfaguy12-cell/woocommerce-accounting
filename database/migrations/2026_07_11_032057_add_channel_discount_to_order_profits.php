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
        Schema::table('order_profits', function (Blueprint $table) {
            // Marketplace-level discount (e.g. a Basalam coupon) that never
            // reaches WooCommerce as a line-item discount — derived from the
            // channel's own settlement metadata, folded into `discounts`/
            // `net_sale` the same way a native WooCommerce discount is.
            $table->unsignedBigInteger('channel_discount')->nullable()->default(0)->after('channel_fee_source');
            $table->string('channel_discount_source', 20)->nullable()->after('channel_discount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_profits', function (Blueprint $table) {
            $table->dropColumn(['channel_discount', 'channel_discount_source']);
        });
    }
};
