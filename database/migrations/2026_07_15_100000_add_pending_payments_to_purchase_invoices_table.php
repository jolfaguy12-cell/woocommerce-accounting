<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            // Initial-payment rows typed on a draft: kept here, unposted, so the
            // edit form can restore them; finalize() posts them via
            // PaymentRecorder and clears this column back to null.
            $table->json('pending_payments')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn('pending_payments');
        });
    }
};
