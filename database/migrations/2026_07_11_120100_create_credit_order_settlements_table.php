<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_order_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_order_id')->constrained('credit_orders')->restrictOnDelete();
            $table->nullableMorphs('source'); // PartyPayment or BadDebtWriteOff — which event settled this slice
            $table->unsignedBigInteger('amount'); // Toman
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_order_settlements');
    }
};
