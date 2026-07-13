<?php

use App\Domain\Accounting\Support\PartyIdentityBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * Copies the role-specific columns off `parties` into the new profiles.
 * Idempotent; re-run at deploy time via `php artisan parties:sync-profiles`,
 * because order sync keeps creating customer parties after this runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        PartyIdentityBackfill::profiles();
    }

    public function down(): void
    {
        // The profile tables are dropped wholesale by their own migration; the
        // legacy columns they were copied from are still on `parties`, untouched.
    }
};
