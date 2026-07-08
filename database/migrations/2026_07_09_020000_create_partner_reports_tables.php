<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_reports', function (Blueprint $table) {
            $table->id();
            $table->string('jalali_period', 7)->unique();
            $table->enum('state', ['draft', 'needs_review', 'final', 'adjusted'])->default('draft')->index();
            $table->json('draft_data')->nullable();  // latest build (mutable until finalized)
            $table->json('snapshot')->nullable();    // immutable after finalize
            $table->json('readiness')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });

        Schema::create('report_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_report_id')->constrained('partner_reports')->restrictOnDelete();
            $table->string('description');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_adjustments');
        Schema::dropIfExists('partner_reports');
    }
};
