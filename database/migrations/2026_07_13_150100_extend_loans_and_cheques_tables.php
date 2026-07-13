<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Completes the loan and cheque tables that have existed, ledger-correct but
 * headless, since the first accounting commit.
 *
 * Additive throughout. The three `status` columns are widened from ENUM to a plain
 * string because the lifecycles genuinely grew (a loan can now be a draft, awaiting
 * approval, overdue, cancelled or reversed — it used to be able to be `active` or
 * `closed`, and nothing else). Widening an ENUM is the one change here that touches
 * an existing column, and it is safe: all three tables are empty in production, and
 * the legacy `closed` value is mapped to `paid` below regardless, so the migration
 * is correct even against a database that ISN'T empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            // Which way the money went. Defaults to `payable` because every loan the
            // old LoanService could create was one we received (it was hard-wired to
            // 2200), so the default is a truthful description of all existing rows.
            $table->string('direction', 12)->default('payable')->after('party_id')->index();

            $table->date('maturity_date')->nullable()->after('received_at');
            $table->string('interest_method', 12)->default('none')->after('maturity_date');
            $table->decimal('interest_rate', 6, 3)->nullable()->after('interest_method'); // annual %
            $table->unsignedBigInteger('interest_amount')->default(0)->after('interest_rate'); // total, Toman
            $table->unsignedSmallInteger('installment_count')->default(0)->after('interest_amount');

            $table->string('reference')->nullable()->after('installment_count');
            $table->text('notes')->nullable()->after('reference');

            $table->foreignId('reversal_entry_id')->nullable()->after('journal_entry_id')
                ->constrained('journal_entries')->restrictOnDelete();

            foreach (['created_by', 'submitted_by', 'approved_by', 'reversed_by', 'cancelled_by'] as $actor) {
                $table->foreignId($actor)->nullable()->constrained('users')->nullOnDelete();
            }
            foreach (['submitted_at', 'approved_at', 'posted_at', 'reversed_at', 'cancelled_at'] as $moment) {
                $table->timestamp($moment)->nullable();
            }
            $table->string('reversal_reason')->nullable();
            $table->string('cancel_reason')->nullable();
        });

        Schema::table('loan_installments', function (Blueprint $table) {
            // The four things an installment payment is actually made of. Kept apart
            // because they hit four different accounts, and a lump "amount" cannot be
            // posted without guessing which.
            $table->unsignedBigInteger('fee_part')->default(0)->after('interest_part');
            $table->unsignedBigInteger('penalty_part')->default(0)->after('fee_part');
            $table->unsignedBigInteger('paid_amount')->default(0)->after('penalty_part');

            $table->unsignedSmallInteger('sequence')->default(1)->after('loan_id');

            // An installment can be paid, un-paid (the payment was a mistake) and paid
            // again. Each posting therefore needs its own idempotency key — without a
            // counter the second payment would collide with the first key and be
            // silently swallowed as a duplicate, leaving the money missing.
            $table->unsignedSmallInteger('payment_attempts')->default(0);

            $table->foreignId('reversal_entry_id')->nullable()->after('journal_entry_id')
                ->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();
        });

        Schema::table('cheques', function (Blueprint $table) {
            // Which of OUR accounts the cheque cleared into (receivable) or out of
            // (payable). Null until it clears — an outstanding cheque has not touched
            // a bank account yet, which is the entire reason 1250/2100 exist.
            $table->foreignId('bank_account_id')->nullable()->after('party_id')
                ->constrained('bank_accounts')->restrictOnDelete();

            $table->string('reference')->nullable()->after('serial');
            $table->string('bank_name')->nullable()->after('reference');
            $table->text('notes')->nullable()->after('description');

            $table->foreignId('reversal_entry_id')->nullable()->after('settlement_entry_id')
                ->constrained('journal_entries')->restrictOnDelete();

            // A cheque can be cleared, un-cleared (we were wrong) and cleared again.
            // Each settlement therefore needs its own idempotency key, and the key
            // needs a counter — without it the second clearing would collide with the
            // first key and be silently swallowed as a duplicate.
            $table->unsignedSmallInteger('settlement_attempts')->default(0);

            foreach (['created_by', 'settled_by', 'reversed_by', 'cancelled_by'] as $actor) {
                $table->foreignId($actor)->nullable()->constrained('users')->nullOnDelete();
            }
            foreach (['settled_at', 'reversed_at', 'cancelled_at'] as $moment) {
                $table->timestamp($moment)->nullable();
            }
            $table->string('reversal_reason')->nullable();
            $table->string('cancel_reason')->nullable();
        });

        // Widen the three lifecycles. Done after the columns above so a failure here
        // leaves a table that is merely un-widened, not half-built.
        Schema::table('loans', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->change();
        });
        Schema::table('loan_installments', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });
        Schema::table('cheques', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });

        // `closed` was the old name for a loan whose principal had been repaid.
        DB::table('loans')->where('status', 'closed')->update(['status' => 'paid']);
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_entry_id');
            foreach (['created_by', 'submitted_by', 'approved_by', 'reversed_by', 'cancelled_by'] as $actor) {
                $table->dropConstrainedForeignId($actor);
            }
            $table->dropColumn([
                'direction', 'maturity_date', 'interest_method', 'interest_rate', 'interest_amount',
                'installment_count', 'reference', 'notes',
                'submitted_at', 'approved_at', 'posted_at', 'reversed_at', 'cancelled_at',
                'reversal_reason', 'cancel_reason',
            ]);
        });

        Schema::table('loan_installments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_entry_id');
            $table->dropConstrainedForeignId('paid_by');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['fee_part', 'penalty_part', 'paid_amount', 'sequence',
                'payment_attempts', 'reversed_at', 'reversal_reason']);
        });

        Schema::table('cheques', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_id');
            $table->dropConstrainedForeignId('reversal_entry_id');
            foreach (['created_by', 'settled_by', 'reversed_by', 'cancelled_by'] as $actor) {
                $table->dropConstrainedForeignId($actor);
            }
            $table->dropColumn([
                'reference', 'bank_name', 'notes', 'settlement_attempts',
                'settled_at', 'reversed_at', 'cancelled_at', 'reversal_reason', 'cancel_reason',
            ]);
        });
    }
};
