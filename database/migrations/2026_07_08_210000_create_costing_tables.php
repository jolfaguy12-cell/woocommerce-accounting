<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sku')->nullable()->index();
            $table->string('unit', 20)->default('عدد');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cost_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('cost_group_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cost_group_id')->constrained('cost_groups')->cascadeOnDelete();
            $table->foreignId('cost_item_id')->constrained('cost_items')->cascadeOnDelete();
            $table->unique(['cost_group_id', 'cost_item_id']);
        });

        Schema::create('product_cost_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_mirror_id')->unique()->constrained('product_mirror')->cascadeOnDelete();
            $table->foreignId('cost_item_id')->nullable()->constrained('cost_items')->restrictOnDelete();
            $table->foreignId('cost_group_id')->nullable()->constrained('cost_groups')->restrictOnDelete();
            $table->decimal('multiplier', 8, 3)->default(1); // packs / multi-unit products
            $table->string('formula')->nullable(); // stored only; evaluation is a future explicit feature
            $table->enum('status', ['mapped', 'unmapped', 'needs_review'])->default('unmapped')->index();
            $table->foreignId('mapped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('supplier_party_id')->constrained('parties')->restrictOnDelete();
            $table->string('invoice_no')->nullable();
            $table->date('invoice_date');
            $table->string('jalali_period', 7)->index();
            $table->unsignedBigInteger('shipping_cost')->default(0); // Toman
            $table->enum('shipping_allocation', ['by_qty', 'manual'])->default('by_qty');
            $table->enum('status', ['draft', 'partial', 'received', 'cancelled'])->default('draft')->index();
            $table->text('notes')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->cascadeOnDelete();
            $table->foreignId('cost_item_id')->constrained('cost_items')->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->unsignedInteger('received_qty')->default(0);
            $table->unsignedBigInteger('unit_price');            // Toman
            $table->unsignedBigInteger('shipping_allocated')->default(0);
            $table->unsignedBigInteger('landed_unit_cost')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('cost_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cost_item_id')->constrained('cost_items')->cascadeOnDelete();
            $table->unsignedBigInteger('unit_cost');
            $table->unsignedBigInteger('landed_unit_cost');
            $table->enum('source', ['invoice', 'manual', 'import']);
            $table->unsignedBigInteger('source_id')->nullable(); // e.g. purchase_invoice_line id
            $table->date('effective_at')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['cost_item_id', 'effective_at']);
        });

        Schema::create('wholesale_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cost_item_id')->constrained('cost_items')->cascadeOnDelete();
            $table->unsignedBigInteger('price'); // Toman, internal only — never sent to WooCommerce
            $table->date('effective_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['cost_item_id', 'effective_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wholesale_prices');
        Schema::dropIfExists('cost_history');
        Schema::dropIfExists('purchase_invoice_lines');
        Schema::dropIfExists('purchase_invoices');
        Schema::dropIfExists('product_cost_mappings');
        Schema::dropIfExists('cost_group_items');
        Schema::dropIfExists('cost_groups');
        Schema::dropIfExists('cost_items');
    }
};
