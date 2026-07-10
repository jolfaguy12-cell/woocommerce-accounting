<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('message_template');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('alert_type_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_type_id')->constrained('alert_types')->cascadeOnDelete();
            $table->string('role');
            $table->unique(['alert_type_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_type_role');
        Schema::dropIfExists('alert_types');
    }
};
