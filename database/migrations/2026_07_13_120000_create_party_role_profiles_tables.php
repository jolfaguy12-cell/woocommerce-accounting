<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role-specific data moves off `parties` and into one profile table per role —
 * the shape `employees` (party_id + base_salary + is_active, no contact fields)
 * already had right, generalized.
 *
 * Shared identity (name, phone, email, address, telegram, identifiers) stays on
 * `parties` and is never duplicated here: a credit limit belongs to being a
 * customer, a phone number belongs to being a person.
 *
 * The legacy columns on `parties` (credit_limit, is_wholesale, wholesale_labeled_*,
 * shop_name, bank_account_number) are deliberately left in place by this
 * migration — they are backfilled here and simply stop being read, so a running
 * production instance can never hit a column that vanished mid-deploy. They are
 * dropped in their own, final migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->unique()->constrained('parties')->cascadeOnDelete();
            $table->bigInteger('credit_limit')->default(0);
            $table->boolean('is_wholesale')->default(false)->index();
            $table->timestamp('wholesale_labeled_at')->nullable();
            $table->foreignId('wholesale_labeled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->unique()->constrained('parties')->cascadeOnDelete();
            $table->string('shop_name')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('partner_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->unique()->constrained('parties')->cascadeOnDelete();
            // Basis points (1% = 100), so a 12.5% share is exact — a float share
            // would not sum to 10000 across partners and profit split must.
            $table->unsignedSmallInteger('ownership_bp')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_profiles');
        Schema::dropIfExists('supplier_profiles');
        Schema::dropIfExists('customer_profiles');
    }
};
