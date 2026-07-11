<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_snapshots', function (Blueprint $table) {
            $table->id();
            // Physical units on hand across simple + variation items (excludes
            // the "variable" parent, which never carries its own stock).
            $table->unsignedBigInteger('total_units');
            // Toman, at current selling price (not landed/purchase cost).
            $table->unsignedBigInteger('total_value');
            $table->timestamp('computed_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_snapshots');
    }
};
