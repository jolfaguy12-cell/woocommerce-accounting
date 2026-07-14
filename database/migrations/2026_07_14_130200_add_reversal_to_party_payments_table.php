<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `party_payments` could record a payment and could not un-record one.
 *
 * Every other posted operation in the system (loans, cheques, partner operations,
 * transfers) can be reversed: the original entry stays exactly as posted and an
 * opposing entry cancels it, with a reason and an actor attached. A payment could
 * not — so a salary paid to the wrong employee, or a settlement typed with an
 * extra zero, had no honest correction at all.
 *
 * Additive. Nothing about an existing payment changes; it simply becomes possible
 * to say "this one was wrong, here is the reversal, and here is who said so".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('party_payments', function (Blueprint $table) {
            $table->foreignId('reversal_entry_id')->nullable()->after('journal_entry_id')
                ->constrained('journal_entries')->restrictOnDelete();
            $table->string('reversal_reason')->nullable()->after('reversal_entry_id');
            $table->foreignId('reversed_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('reversed_by');
        });
    }

    public function down(): void
    {
        Schema::table('party_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_entry_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversal_reason', 'reversed_at']);
        });
    }
};
