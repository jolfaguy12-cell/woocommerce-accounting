<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_labels', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->nullable()->unique();
            $table->string('color', 20)->default('light');
            $table->timestamps();
        });

        Schema::create('order_label_order', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_label_id')->constrained('order_labels')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['order_id', 'order_label_id']);
        });

        DB::table('order_labels')->insert([
            'name' => 'سفارش عمده',
            'slug' => 'wholesale',
            'color' => 'warning',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_label_order');
        Schema::dropIfExists('order_labels');
    }
};
