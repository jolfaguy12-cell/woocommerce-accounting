<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A posted payroll run is history, and history is corrected by reversal — never
 * by editing the run that was posted. The columns below are what a reversal needs
 * to be a record rather than an erasure: the opposing entry, the reason, who did
 * it and when.
 *
 * `status` is widened from ENUM('draft','posted') to a string for the same reason
 * the loan/cheque lifecycles were (2026_07_13_150100): the lifecycle grew a third
 * state and an ENUM cannot be extended without rewriting the column anyway.
 *
 * The unique index on (payroll_run_id, employee_id) closes the door on the same
 * employee being accrued twice inside one run — the duplicate-accrual bug that is
 * hardest to see, because the entry still balances perfectly and only the
 * employee's «مانده حقوق» is wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->foreignId('reversal_entry_id')->nullable()->after('journal_entry_id')
                ->constrained('journal_entries')->restrictOnDelete();
            $table->string('reversal_reason')->nullable()->after('reversal_entry_id');
            $table->foreignId('reversed_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('reversed_by');
            $table->timestamp('posted_at')->nullable()->after('reversed_at');
            $table->string('notes')->nullable()->after('posted_at');
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->change();
        });

        Schema::table('payroll_items', function (Blueprint $table) {
            $table->unique(['payroll_run_id', 'employee_id'], 'payroll_items_run_employee_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropUnique('payroll_items_run_employee_unique');
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_entry_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversal_reason', 'reversed_at', 'posted_at', 'notes']);
        });
    }
};
