<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_costs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('channel_id')->constrained('channels')->restrictOnDelete();
            $table->string('jalali_period', 7)->index();
            $table->enum('type', ['topup', 'manual']);
            $table->unsignedBigInteger('amount'); // Toman
            $table->date('occurred_at');
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['channel_id', 'jalali_period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_costs');
    }
};
