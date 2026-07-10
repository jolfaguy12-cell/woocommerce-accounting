<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_gateway_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('tracking_code');
            $table->timestamp('checked_at');
            $table->integer('zibal_result_code')->nullable();
            $table->string('zibal_status')->nullable();
            $table->bigInteger('zibal_amount')->nullable();
            $table->boolean('mismatch')->default(false);
            $table->json('raw_response')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_gateway_checks');
    }
};
