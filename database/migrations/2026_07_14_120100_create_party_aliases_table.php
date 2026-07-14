<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Party merge, without rewriting a single journal line.
 *
 * The obvious merge — repoint every `journal_lines.party_id` at the survivor —
 * is forbidden here: journal lines are immutable, and rewriting them would edit
 * posted history to make a UI problem go away. So the merged party is never
 * erased and its id is never reused: it stays in the ledger exactly where it
 * always was, and this table records that it is the SAME identity as the
 * survivor. Balances then aggregate over `parties.merged_into_id`-linked ids
 * (see Party::identityIds()), so the survivor's profile shows the whole history
 * of both — while every historical entry still points at the id it was posted
 * with, and still reconciles.
 *
 * `snapshot` keeps what the absorbed party looked like at merge time, so the
 * decision is auditable even after the survivor's own fields are edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();       // survivor
            $table->foreignId('merged_party_id')->unique()->constrained('parties')->restrictOnDelete(); // absorbed
            $table->string('reason');
            $table->json('snapshot')->nullable();
            $table->timestamp('merged_at');
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('party_id');
        });

        Schema::table('parties', function (Blueprint $table) {
            // Set on the ABSORBED party. Its rows stay; it is simply no longer a
            // separate identity, and every list filters it out with notMerged().
            $table->foreignId('merged_into_id')->nullable()->after('id')
                ->constrained('parties')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_id');
        });

        Schema::dropIfExists('party_aliases');
    }
};
