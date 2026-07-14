<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A login is not an identity.
 *
 * `users` answers "who may sign in and what may they do" (Spatie roles —
 * «سطح دسترسی سیستم»). `parties` answers "who is this person to the business"
 * (party roles — «نقش‌های تجاری»). They were entirely disconnected, so the
 * bookkeeper who is also a partner existed twice with no link between the two,
 * and their salary had nowhere to attach.
 *
 * Nullable on purpose: an `admin` login that is nobody's employee or partner is
 * a perfectly normal thing and must not be forced to invent a Party.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('party_id')->nullable()->after('id')
                ->constrained('parties')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('party_id');
        });
    }
};
