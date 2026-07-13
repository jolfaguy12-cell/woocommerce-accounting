<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generalises `party_payments` in place — it is NOT replaced. Every existing row,
 * journal entry and idempotency key stays exactly where it is; the table simply
 * learns to say what a payment was FOR.
 *
 * `purpose` is nullable on purpose. Existing rows were written before purposes
 * existed, and their direction alone cannot tell a customer receipt from a
 * supplier refund (both are 'in'). Guessing would be inventing a financial
 * classification and stamping it on history, so they stay NULL — honestly
 * "recorded before this was captured" — and only new writes carry a purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('party_payments', function (Blueprint $table) {
            $table->string('purpose', 40)->nullable()->after('direction')->index();

            // The counterparty's OWN bank account (theirs, not ours) — which card or
            // IBAN we actually paid, so a payment can be reconciled against a bank
            // statement months later. Never an internal ledger account.
            $table->foreignId('party_bank_account_id')->nullable()->after('bank_account_id')
                ->constrained('party_bank_accounts')->nullOnDelete();

            // The date the entry is posted on, when it differs from paid_at. Existing
            // methods leave it NULL and keep posting on "now", so their behaviour is
            // unchanged; only a caller that explicitly backdates sets it.
            $table->date('accounting_date')->nullable()->after('paid_at');

            // How much of an outgoing supplier payment ran ahead of the invoices and
            // landed on 1450 instead of settling 2000. Derivable from the journal, but
            // stored so the supplier page can show "of which advance" without
            // re-deriving it, and so the reclass command can prove its own work.
            // (`method` and `reference` already exist — 2026_07_13_090300.)
            $table->unsignedBigInteger('advance_amount')->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('party_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('party_bank_account_id');
            $table->dropColumn(['purpose', 'accounting_date', 'advance_amount']);
        });
    }
};
