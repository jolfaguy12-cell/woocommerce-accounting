<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «حساب دوطرفه» — netting two balances the SAME party holds against each other:
 * the customer who is also a supplier, the credit we hold against the invoice
 * they still owe, the advance we paid against the bill that finally arrived.
 *
 * Until now the only way to do this was to pretend cash moved. It didn't: an
 * offset moves no money at all, and its whole point is that it is a deliberate,
 * posted, reversible decision rather than a silent edit of two balances.
 *
 * `type` (not a free pair of account ids) decides which account is debited and
 * which credited — see PartyOffsetType. An arbitrary account pair would let
 * someone net a payable against sales revenue, which balances perfectly and means
 * nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_offsets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // ONE party. Both legs of an offset belong to the same real person or
            // company — that is what makes it an offset and not a payment.
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();

            $table->string('type', 40);            // PartyOffsetType
            $table->unsignedBigInteger('amount');  // Toman

            $table->date('offset_date');
            $table->string('jalali_period', 7)->index();
            $table->string('reason');              // required: an unexplained offset is an unexplainable balance
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
        Schema::dropIfExists('party_offsets');
    }
};
