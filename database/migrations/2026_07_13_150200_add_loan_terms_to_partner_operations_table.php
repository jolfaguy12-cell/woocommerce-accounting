<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The terms a partner loan is to be created with, held until the operation posts.
 *
 * A partner loan does not become a Loan the moment it is typed in — if it needs
 * approval it sits pending for days first. The maturity date, interest method and
 * installment count have to survive that wait somewhere, and re-asking the approver
 * for them would mean the thing approved was not the thing recorded.
 *
 * Only the terms live here. `loan_id` (already on this table) is the answer, and it
 * is filled in the moment LoanService actually creates the contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_operations', function (Blueprint $table) {
            $table->json('loan_terms')->nullable()->after('loan_id');
        });
    }

    public function down(): void
    {
        Schema::table('partner_operations', function (Blueprint $table) {
            $table->dropColumn('loan_terms');
        });
    }
};
