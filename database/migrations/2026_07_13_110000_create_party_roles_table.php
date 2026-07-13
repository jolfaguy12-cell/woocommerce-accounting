<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One Party can now hold several roles at once (customer AND supplier AND …).
 * `role` is a plain indexed string, not a DB enum, so adding a role type is a
 * code change (PartyRoleType) rather than a migration.
 *
 * Deactivation is is_active=false + deactivated_at — never a delete, so
 * "was a supplier from X to Y" survives, matching how JournalEntry/Cheque/Loan
 * already use status flags instead of removing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->string('role', 32)->index();
            $table->boolean('is_active')->default(true);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The enforcement point for "a party cannot hold the same role twice";
            // re-activating is an update of this row, never a second insert.
            $table->unique(['party_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_roles');
    }
};
