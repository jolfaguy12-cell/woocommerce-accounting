<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employee's own facts, and only the ones «حساب کارمند» actually needs.
 *
 * Deliberately NOT an HR record: no attendance, no contract terms, no insurance,
 * no tax file. Everything about the person — name, phone, national id, bank
 * accounts — already lives on their Party, because an employee is a Party with
 * the employee role, not a second kind of entity. What is genuinely employment
 * data, and had nowhere to live, is the job title, the day they started and the
 * monthly figure their payroll is proposed from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('job_title', 100)->nullable()->after('party_id');
            $table->date('hired_at')->nullable()->after('base_salary');
            $table->text('notes')->nullable()->after('hired_at');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['job_title', 'hired_at', 'notes']);
        });
    }
};
