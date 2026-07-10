<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('bank_deposit_imports');
            $table->string('source')->default('zibal_export');
            $table->string('external_reference');
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->string('destination_iban')->nullable();
            $table->string('account_holder_name')->nullable();
            $table->string('psp_label')->nullable();
            $table->string('status')->nullable();
            $table->bigInteger('amount_toman');
            $table->bigInteger('fee_toman')->default(0);
            $table->dateTime('registered_at')->nullable();
            $table->dateTime('deposited_at');
            $table->string('tracking_id')->nullable();
            $table->string('related_settlement_ids')->nullable();
            $table->json('raw_row')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source', 'external_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_deposits');
    }
};
