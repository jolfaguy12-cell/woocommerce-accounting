<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who actually paid for the expense.
 *
 * Until now every expense credited a company bank account, because
 * `bank_account_id` was NOT NULL and `ExpenseRecorder` had no other option. So
 * an expense the company had not paid yet, and an expense an employee paid out
 * of their own pocket, were both recorded as company cash leaving the building.
 * The first overstates what was spent; the second invents a payment that never
 * happened AND loses the debt owed to the employee.
 *
 * `funding_source` names the credit side. Existing rows are all `bank` — which
 * is exactly what they were, so the backfill is the truth, not an assumption.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('funding_source', 20)->default('bank')->after('bank_account_id')->index();
            // The employee/partner who paid, or the supplier we now owe. Distinct
            // from `party_id`, which is who the expense is ABOUT.
            $table->foreignId('funded_by_party_id')->nullable()->after('funding_source')
                ->constrained('parties')->restrictOnDelete();
        });

        // Only a bank-funded expense has a bank account. Nothing else can.
        // unsignedBigInteger, not foreignId: the FK already exists and change()
        // must modify the column, not try to add the constraint a second time.
        Schema::table('expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('bank_account_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('funded_by_party_id');
            $table->dropColumn('funding_source');
        });
    }
};
