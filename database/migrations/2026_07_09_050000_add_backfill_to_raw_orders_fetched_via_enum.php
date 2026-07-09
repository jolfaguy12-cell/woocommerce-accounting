<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Widens the fetched_via enum on databases created before 'backfill' was added
// to the source migration (create_sync_tables). Fresh installs — including the
// sqlite test database — already get 'backfill' from that migration, so this
// is a MySQL-only patch for already-provisioned environments.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE raw_orders MODIFY fetched_via ENUM('webhook', 'poll', 'manual', 'backfill') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE raw_orders MODIFY fetched_via ENUM('webhook', 'poll', 'manual') NOT NULL");
        }
    }
};
