<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->nullable()->unique()->constrained('orders')->restrictOnDelete();
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->unsignedBigInteger('total_due'); // Toman
            $table->unsignedBigInteger('paid_total')->default(0);
            $table->date('due_date')->nullable();
            $table->enum('status', ['open', 'settled'])->default('open')->index();
            $table->string('description')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('party_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->enum('direction', ['in', 'out']);
            $table->unsignedBigInteger('amount'); // Toman
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->nullableMorphs('applied');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->date('paid_at');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->unique()->constrained('parties')->restrictOnDelete();
            $table->unsignedBigInteger('base_salary')->default(0); // Toman / month
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('jalali_period', 7)->index();
            $table->date('run_date');
            $table->enum('status', ['draft', 'posted'])->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->unsignedBigInteger('gross');
            $table->unsignedBigInteger('advances_deducted')->default(0);
            $table->unsignedBigInteger('net');
            $table->timestamps();
        });

        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->unsignedBigInteger('principal'); // Toman
            $table->date('received_at');
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->enum('status', ['active', 'closed'])->default('active');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('loan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('principal_part');
            $table->unsignedBigInteger('interest_part')->default(0);
            $table->date('paid_at')->nullable();
            $table->date('due_date')->nullable();
            $table->enum('status', ['pending', 'paid'])->default('pending');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('cheques', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->enum('direction', ['receivable', 'payable']);
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->unsignedBigInteger('amount'); // Toman
            $table->date('due_date')->index();
            $table->enum('status', ['pending', 'cleared', 'bounced'])->default('pending')->index();
            $table->string('serial')->nullable();
            $table->string('description')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('settlement_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cheques');
        Schema::dropIfExists('loan_installments');
        Schema::dropIfExists('loans');
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('party_payments');
        Schema::dropIfExists('credit_orders');
    }
};
