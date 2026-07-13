<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A direct deposit into, or withdrawal from, one of our accounts.
 *
 * `counter_account_id` is NOT NULL on purpose: money never appears or vanishes.
 * Every movement must say where it came from or went to, which makes a
 * "balance-only adjustment" — the classic way a ledger silently stops
 * reconciling — impossible to express in this table at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->string('direction', 3);  // in = deposit, out = withdrawal
            $table->foreignId('counter_account_id')->constrained('accounts')->restrictOnDelete();

            $table->string('purpose', 30);
            $table->foreignId('party_id')->nullable()->constrained('parties')->restrictOnDelete();

            $table->unsignedBigInteger('amount'); // Toman
            $table->date('transaction_date');
            $table->string('jalali_period', 7)->index();
            $table->string('method', 30)->nullable();
            $table->string('reference')->nullable();
            $table->string('description');
            $table->text('notes')->nullable();

            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();

            foreach (['created_by', 'submitted_by', 'approved_by', 'reversed_by', 'cancelled_by'] as $actor) {
                $table->foreignId($actor)->nullable()->constrained('users')->nullOnDelete();
            }
            foreach (['submitted_at', 'approved_at', 'posted_at', 'reversed_at', 'cancelled_at'] as $moment) {
                $table->timestamp($moment)->nullable();
            }
            $table->string('reversal_reason')->nullable();
            $table->string('cancel_reason')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_transactions');
    }
};
