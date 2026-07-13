<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoice_receipt_lines', function (Blueprint $table) {
            $table->boolean('via_toggle')->default(false)->after('package_label');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_receipt_lines', function (Blueprint $table) {
            $table->dropColumn('via_toggle');
        });
    }
};
