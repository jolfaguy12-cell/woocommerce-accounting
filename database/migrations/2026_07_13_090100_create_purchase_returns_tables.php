<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoice_lines', function (Blueprint $table) {
            $table->unsignedInteger('returned_qty')->default(0)->after('received_qty');
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->restrictOnDelete();
            $table->string('reason');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('purchase_returns')->cascadeOnDelete();
            $table->foreignId('purchase_invoice_line_id')->constrained('purchase_invoice_lines')->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->unsignedBigInteger('unit_cost'); // landed cost at time of return, Toman
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_lines');
        Schema::dropIfExists('purchase_returns');

        Schema::table('purchase_invoice_lines', function (Blueprint $table) {
            $table->dropColumn('returned_qty');
        });
    }
};
