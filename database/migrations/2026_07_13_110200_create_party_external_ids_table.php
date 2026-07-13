<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External-channel identifiers for a Party (Basalam buyer id, marketplace
 * account, gateway customer ref, …). parties.hub_customer_id stays exactly
 * where it is — it is load-bearing for order sync and is not migrated here.
 *
 * unique(source, external_id): one external identity maps to at most one Party.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_external_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->string('source', 64);        // e.g. basalam, telegram, gateway
            $table->string('external_id', 191);
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->index(['party_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_external_ids');
    }
};
