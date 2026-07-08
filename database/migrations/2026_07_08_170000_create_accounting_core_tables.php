<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->enum('type', ['asset', 'liability', 'equity', 'revenue', 'expense']);
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['customer', 'supplier', 'employee', 'partner', 'other']);
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->unsignedBigInteger('hub_customer_id')->nullable()->unique();
            $table->bigInteger('credit_limit')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['type', 'name']);
        });

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->string('jalali_period', 7)->unique(); // e.g. "1405-04"
            $table->date('starts_at');
            $table->date('ends_at');
            $table->enum('status', ['open', 'soft_closed', 'locked'])->default('open');
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->date('entry_date');
            $table->string('jalali_period', 7)->index();
            $table->string('description');
            $table->nullableMorphs('source');
            $table->enum('status', ['posted', 'reversed'])->default('posted');
            $table->foreignId('reversed_by_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_of_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->string('correlation_id')->nullable()->index();
            $table->string('idempotency_key')->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['jalali_period', 'status']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->unsignedBigInteger('debit')->default(0);   // Toman, integer only
            $table->unsignedBigInteger('credit')->default(0);  // Toman, integer only
            $table->foreignId('party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->restrictOnDelete();
            $table->string('memo')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'journal_entry_id']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('parties');
        Schema::dropIfExists('cost_centers');
        Schema::dropIfExists('accounts');
    }
};
