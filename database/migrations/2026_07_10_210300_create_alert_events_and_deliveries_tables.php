<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_type_id')->constrained('alert_types');
            $table->nullableMorphs('subject');
            $table->json('data')->nullable();
            $table->text('rendered_message');
            $table->string('status')->default('pending'); // pending|dispatched|skipped_inactive|skipped_no_recipients
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('alert_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_event_id')->constrained('alert_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('channel')->default('telegram');
            $table->string('status')->default('pending'); // pending|sent|failed|skipped_no_telegram_id
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_deliveries');
        Schema::dropIfExists('alert_events');
    }
};
