<?php

use App\Domain\Accounting\Support\PartyIdentityBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last of the single-role Party.
 *
 * `parties.type` was a role field wearing an identity field's clothes: one real
 * person could be a customer OR a supplier, never both. Roles moved to
 * `party_roles`, and the role-specific columns that were squatting on `parties`
 * moved to their role profiles. Nothing has read any of them since Commit 2.
 *
 * This is the only destructive migration in the whole upgrade, and it is
 * deliberately last: every migration before it is additive, so a rollback at any
 * earlier point costs nothing. Here, it costs the legacy columns — which is why
 * the backfill runs one final time immediately before the drop, in the same
 * transaction as the schema change. It is idempotent, so on a database whose
 * earlier backfill migrations already ran (every real one) it copies nothing and
 * simply proves there is nothing left to copy.
 *
 * IRREVERSIBLE IN PRACTICE. down() restores the columns so the schema can be
 * rolled back, but the DATA in them is gone: it now lives in party_roles,
 * customer_profiles, supplier_profiles and party_bank_accounts, and those are
 * the only copies. Rolling back past this point means restoring from the backup
 * taken before deploy — which is exactly why one is taken.
 */
return new class extends Migration
{
    private const LEGACY_COLUMNS = [
        'type',
        'credit_limit',
        'is_wholesale',
        'wholesale_labeled_at',
        'wholesale_labeled_by',
        'shop_name',
        'bank_account_number',
    ];

    public function up(): void
    {
        // Last chance to move anything still sitting in these columns. On a
        // database that has run the earlier backfills this is a no-op; on one
        // where a party slipped in between migrations, it is the difference
        // between that party keeping its role and losing it forever.
        PartyIdentityBackfill::roles();
        PartyIdentityBackfill::profiles();
        PartyIdentityBackfill::normalizedPhones();

        // Everything hanging off these columns has to come off first — MySQL will
        // not drop a column that an index or a foreign key still points at, and
        // SQLite silently corrupts the index if it does. Three separate things,
        // all easy to miss: the composite index leads with `type`, `is_wholesale`
        // carries its own index, and `wholesale_labeled_by` is a FOREIGN KEY to
        // users (its index is created implicitly and must be named explicitly).
        Schema::table('parties', function (Blueprint $table) {
            $table->dropForeign(['wholesale_labeled_by']);
        });

        Schema::table('parties', function (Blueprint $table) {
            $table->dropIndex(['type', 'name']);
            $table->dropIndex(['is_wholesale']);
        });

        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn(self::LEGACY_COLUMNS);
        });

        Schema::table('parties', function (Blueprint $table) {
            // `name` was only ever indexed as the tail of ['type','name'], which a
            // name lookup could not use. Every remaining query searches by name
            // alone (party search, duplicate detection), so give it the index it
            // has actually been wanting all along.
            $table->index('name');
        });
    }

    /**
     * Restores the SHAPE, not the data. See the class docblock: rolling back past
     * this migration is a restore-from-backup operation, not a `migrate:rollback`.
     */
    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->dropIndex(['name']);
        });

        Schema::table('parties', function (Blueprint $table) {
            // Nullable, unlike the original NOT NULL `type`: there is no value to
            // put back, and inventing one ('customer' for every party) would be a
            // lie the next reader would have no way to detect.
            $table->string('type', 20)->nullable()->after('id');
            $table->unsignedBigInteger('credit_limit')->default(0);
            $table->boolean('is_wholesale')->default(false)->index();
            $table->timestamp('wholesale_labeled_at')->nullable();
            $table->foreignId('wholesale_labeled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('shop_name')->nullable();
            $table->string('bank_account_number')->nullable();
        });

        Schema::table('parties', function (Blueprint $table) {
            $table->index(['type', 'name']);
        });
    }
};
