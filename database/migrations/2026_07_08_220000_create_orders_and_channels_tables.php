<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('cost_model', ['none', 'manual_period', 'wallet_topup', 'order_commission', 'api_enriched'])->default('none');
            $table->json('config')->nullable(); // e.g. commission meta key for order_commission
            $table->json('valid_statuses')->nullable(); // statuses that make an order financially valid
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('channel_sources', function (Blueprint $table) {
            $table->id();
            $table->string('raw_value')->unique(); // normalized raw source string from order payloads
            $table->foreignId('channel_id')->nullable()->constrained('channels')->restrictOnDelete();
            $table->json('raw_signature')->nullable(); // provenance: which payload field carried the value
            $table->enum('status', ['mapped', 'unknown', 'ignored'])->default('unknown')->index();
            $table->unsignedInteger('order_count')->default(0);
            $table->timestamp('first_seen_at');
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raw_order_id')->constrained('raw_orders')->restrictOnDelete();
            $table->unsignedBigInteger('hub_order_id')->unique();
            $table->string('status', 50)->index();
            $table->string('created_via', 50)->nullable();
            $table->timestamp('order_date');
            $table->string('jalali_period', 7)->index();
            $table->foreignId('customer_party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->string('currency_raw', 10)->nullable();
            $table->unsignedBigInteger('discount_total')->default(0);   // Toman
            $table->unsignedBigInteger('shipping_charged')->default(0); // Toman
            $table->unsignedBigInteger('total')->default(0);            // Toman
            $table->string('payment_method')->nullable();
            $table->string('payment_method_title')->nullable();
            $table->string('external_order_id')->nullable();
            $table->string('raw_source_value')->nullable()->index();
            $table->foreignId('channel_id')->nullable()->constrained('channels')->restrictOnDelete();
            $table->foreignId('channel_source_id')->nullable()->constrained('channel_sources')->restrictOnDelete();
            $table->enum('financial_state', ['pending', 'valid', 'refunded', 'partially_refunded', 'cancelled', 'void'])->default('pending')->index();
            $table->enum('profit_status', ['pending', 'ok', 'blocked_missing_cost', 'unknown_source', 'needs_review'])->default('pending')->index();
            $table->timestamp('normalized_at');
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('hub_item_id');
            $table->unsignedBigInteger('hub_product_id')->nullable();
            $table->unsignedBigInteger('hub_variation_id')->nullable();
            $table->foreignId('product_mirror_id')->nullable()->constrained('product_mirror')->restrictOnDelete();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->unsignedInteger('qty');
            $table->unsignedBigInteger('unit_price')->default(0);    // Toman
            $table->unsignedBigInteger('line_subtotal')->default(0); // Toman, before discounts
            $table->unsignedBigInteger('line_total')->default(0);    // Toman, after discounts
            $table->timestamps();
            $table->unique(['order_id', 'hub_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('channel_sources');
        Schema::dropIfExists('channels');
    }
};
