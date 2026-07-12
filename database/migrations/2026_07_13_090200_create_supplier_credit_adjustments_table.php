<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_credit_adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->unsignedBigInteger('amount'); // Toman
            $table->string('description');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_credit_adjustments');
    }
};
