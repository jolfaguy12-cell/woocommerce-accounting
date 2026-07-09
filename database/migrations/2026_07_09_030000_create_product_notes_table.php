<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_mirror_id')->constrained('product_mirror')->cascadeOnDelete();
            $table->string('title', 150);
            $table->text('body')->nullable();
            $table->decimal('multiplier', 8, 3)->nullable(); // optional pack/unit factor recorded with the note
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_notes');
    }
};
