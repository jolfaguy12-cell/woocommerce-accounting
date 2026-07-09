<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_uuid')->unique();
            $table->string('event_type', 100)->index();
            $table->json('payload');
            $table->enum('status', ['received', 'processing', 'done', 'failed', 'dead'])->default('received')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('correlation_id')->nullable()->index();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('raw_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hub_order_id')->unique();
            $table->json('payload');
            $table->string('payload_hash', 64);
            $table->enum('fetched_via', ['webhook', 'poll', 'manual', 'backfill']);
            $table->timestamp('hub_modified_at')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50)->index();
            $table->string('since_cursor')->nullable();
            $table->json('stats')->nullable();
            $table->enum('status', ['running', 'done', 'failed'])->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('external_id_map', function (Blueprint $table) {
            $table->id();
            $table->string('external_system', 50)->default('hub');
            $table->string('external_type', 50);
            $table->string('external_id', 100);
            $table->string('internal_type', 50);
            $table->unsignedBigInteger('internal_id');
            $table->timestamps();
            $table->unique(['external_system', 'external_type', 'external_id'], 'external_id_map_unique');
            $table->index(['internal_type', 'internal_id']);
        });

        Schema::create('review_items', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50)->index(); // missing_cost / unmapped_product / unknown_source / missing_shipping / sync_error / ...
            $table->nullableMorphs('subject');
            $table->json('payload')->nullable();
            $table->enum('status', ['open', 'resolved', 'dismissed'])->default('open')->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_items');
        Schema::dropIfExists('external_id_map');
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('raw_orders');
        Schema::dropIfExists('webhook_events');
    }
};
