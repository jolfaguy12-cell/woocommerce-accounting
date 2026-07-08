<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_shipping_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('real_cost'); // Toman, manually entered
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('order_profits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('gross_sale')->default(0);
            $table->unsignedBigInteger('discounts')->default(0);
            $table->unsignedBigInteger('net_sale')->default(0);
            $table->unsignedBigInteger('product_cost')->nullable(); // null = unknown, NEVER zero-filled
            $table->json('cost_breakdown')->nullable(); // per item: cost_item, unit cost, source, multiplier
            $table->unsignedBigInteger('shipping_charged')->default(0);
            $table->unsignedBigInteger('shipping_real')->nullable();
            $table->string('shipping_basis', 20)->nullable(); // manual / default / customer_paid
            $table->unsignedBigInteger('channel_fee')->default(0);
            $table->string('channel_fee_source', 20)->nullable(); // metadata / none
            $table->unsignedBigInteger('gateway_fee')->default(0);
            $table->bigInteger('gross_profit')->nullable();
            $table->bigInteger('operational_profit')->nullable();
            $table->enum('status', ['final', 'provisional', 'blocked', 'reversed'])->index();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->string('inputs_hash', 64)->nullable(); // change detection for recalc
            $table->timestamp('calculated_at');
            $table->timestamps();
        });

        Schema::create('order_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('amount'); // Toman
            $table->string('reason');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_refunds');
        Schema::dropIfExists('order_profits');
        Schema::dropIfExists('order_shipping_costs');
    }
};
