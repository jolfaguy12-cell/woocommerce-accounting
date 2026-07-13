<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything a business partner can do with the company's money.
 *
 * Deliberately NOT generic income/expense. A partner putting money in is capital,
 * not revenue; taking money out is a withdrawal, not a cost; lending the company
 * money is a loan that must be repaid. Collapsing those into "some money moved"
 * is how a partner's stake, their drawings and their loan become impossible to
 * tell apart — and the partner report stops meaning anything. Each type keeps its
 * own accounts (see PartnerOperationType).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->string('type', 40)->index();   // PartnerOperationType
            $table->unsignedBigInteger('amount');  // Toman

            // Cash-moving types only (a profit distribution moves no money — it
            // reclassifies capital into a payable, and is settled later).
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();

            // Required only by expense reimbursement: WHICH expense the partner
            // covered out of their own pocket. Gated to eligible expense accounts.
            $table->foreignId('counter_account_id')->nullable()->constrained('accounts')->restrictOnDelete();

            // A partner loan is a real loan and keeps its installment schedule in
            // `loans`; this row is the partner-facing record of the same event.
            $table->foreignId('loan_id')->nullable()->constrained('loans')->restrictOnDelete();

            $table->date('operation_date');
            $table->string('jalali_period', 7)->index();
            $table->string('description');
            $table->string('reference')->nullable();
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
        Schema::dropIfExists('partner_operations');
    }
};
