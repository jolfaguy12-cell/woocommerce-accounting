<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A counterparty's OWN bank details («حساب بانکی طرف حساب») — where we send
 * their money, or where theirs came from.
 *
 * This is NOT an internal ledger account and must never be treated as one:
 * internal cash/bank accounts are `bank_accounts`, each of which owns a unique
 * accounts.id and can be posted to. Nothing in this table has an account_id,
 * and nothing here may ever get one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->string('bank_name')->nullable();
            $table->string('account_holder')->nullable();
            $table->string('account_number', 50)->nullable();
            $table->string('card_number', 32)->nullable();
            $table->string('iban', 34)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['party_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_bank_accounts');
    }
};
