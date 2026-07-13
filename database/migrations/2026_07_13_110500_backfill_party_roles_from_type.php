<?php

use App\Domain\Accounting\Support\PartyIdentityBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * Straight 1:1 copy of every existing parties.type into party_roles, plus the
 * normalized_phone fill. Zero data loss, no Party IDs change, no foreign key
 * moves — parties.type itself is left alone and is dropped much later, in its
 * own migration, once nothing reads it.
 *
 * Re-run at deploy time via `php artisan parties:backfill-roles` (see the
 * command): order sync creates new parties continuously, so this migration's
 * result is stale the moment it finishes.
 */
return new class extends Migration
{
    public function up(): void
    {
        PartyIdentityBackfill::roles();
        PartyIdentityBackfill::normalizedPhones();
    }

    public function down(): void
    {
        // Nothing to undo: party_roles is dropped wholesale by its own migration,
        // and normalized_phone is a derived column on parties that goes with it.
    }
};
