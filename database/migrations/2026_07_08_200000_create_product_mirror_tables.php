<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_mirror', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hub_product_id')->unique(); // WP post id (products AND variations)
            $table->unsignedBigInteger('parent_hub_id')->nullable()->index(); // set for variations
            $table->string('type', 20); // simple / variable / variation
            $table->string('name');
            $table->string('sku')->nullable()->index();
            $table->string('gtin', 32)->nullable()->index();
            $table->string('status', 20)->nullable();
            $table->unsignedBigInteger('price')->nullable();          // Toman
            $table->unsignedBigInteger('regular_price')->nullable();  // Toman
            $table->unsignedBigInteger('sale_price')->nullable();     // Toman
            $table->integer('stock_quantity')->nullable();
            $table->string('stock_status', 20)->nullable();
            $table->json('payload');
            $table->timestamp('hub_modified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_mirror_id')->constrained('product_mirror')->cascadeOnDelete();
            $table->unsignedBigInteger('old_price')->nullable();
            $table->unsignedBigInteger('new_price')->nullable();
            $table->string('source', 20); // webhook / poll / manual
            $table->string('correlation_id')->nullable()->index();
            $table->timestamp('changed_at');
            $table->timestamps();
        });

        Schema::create('product_stock_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_mirror_id')->constrained('product_mirror')->cascadeOnDelete();
            $table->integer('old_quantity')->nullable();
            $table->integer('new_quantity')->nullable();
            $table->string('source', 20);
            $table->string('correlation_id')->nullable()->index();
            $table->timestamp('changed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stock_history');
        Schema::dropIfExists('product_price_history');
        Schema::dropIfExists('product_mirror');
    }
};
