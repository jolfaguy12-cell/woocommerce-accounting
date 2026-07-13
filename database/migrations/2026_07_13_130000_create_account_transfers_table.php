<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moving money between two of our OWN accounts — the one thing the system could
 * not do. Both sides are existing `bank_accounts` rows (each already owns a
 * unique ledger account), so no "internal account" concept is invented here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('from_bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('to_bank_account_id')->constrained('bank_accounts')->restrictOnDelete();

            $table->unsignedBigInteger('amount');               // Toman
            $table->unsignedBigInteger('bank_fee')->default(0); // borne by the SOURCE account, posted to 6350

            $table->date('transfer_date');
            $table->string('jalali_period', 7)->index();
            $table->string('method', 30)->nullable();  // internal | card | sheba | cash | other
            $table->string('reference')->nullable();   // bank tracking code
            $table->text('notes')->nullable();

            // draft → pending_approval → posted → reversed; cancelled from draft/pending.
            // A journal entry exists only from `posted` onwards.
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
        Schema::dropIfExists('account_transfers');
    }
};
