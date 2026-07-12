<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('party_payments', function (Blueprint $table) {
            $table->string('method', 30)->nullable()->after('amount');
            $table->string('reference', 100)->nullable()->after('method');
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('party_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['method', 'reference']);
        });
    }
};
