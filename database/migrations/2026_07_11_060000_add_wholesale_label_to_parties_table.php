<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->boolean('is_wholesale')->default(false)->after('notes')->index();
            $table->timestamp('wholesale_labeled_at')->nullable()->after('is_wholesale');
            $table->foreignId('wholesale_labeled_by')->nullable()->after('wholesale_labeled_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wholesale_labeled_by');
            $table->dropColumn(['is_wholesale', 'wholesale_labeled_at']);
        });
    }
};
