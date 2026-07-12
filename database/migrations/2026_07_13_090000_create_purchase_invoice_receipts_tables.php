<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoice_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->cascadeOnDelete();
            $table->date('received_at');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_invoice_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained('purchase_invoice_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_invoice_line_id')->constrained('purchase_invoice_lines')->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->unsignedInteger('package_count')->nullable();
            $table->string('package_label', 50)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_receipt_lines');
        Schema::dropIfExists('purchase_invoice_receipts');
    }
};
