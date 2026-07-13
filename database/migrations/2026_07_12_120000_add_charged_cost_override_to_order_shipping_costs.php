<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_shipping_costs', function (Blueprint $table) {
            // Overrides orders.shipping_charged (synced verbatim from the hub's
            // WooCommerce mirror) for cases the hub can never see: a discount/
            // free-shipping deal struck directly with the customer that never
            // reaches WooCommerce's own shipping_total. Nullable — null means
            // "trust the synced value", so most orders are unaffected.
            $table->unsignedBigInteger('charged_cost')->nullable()->after('real_cost');

            // Was NOT NULL (every submit of the shipping form had to carry a
            // real cost). Now independently optional so staff can correct just
            // the charged side (e.g. this bug) without being forced to also
            // supply a real-cost override in the same submission.
            $table->unsignedBigInteger('real_cost')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_shipping_costs', function (Blueprint $table) {
            $table->dropColumn('charged_cost');
            $table->unsignedBigInteger('real_cost')->nullable(false)->change();
        });
    }
};
