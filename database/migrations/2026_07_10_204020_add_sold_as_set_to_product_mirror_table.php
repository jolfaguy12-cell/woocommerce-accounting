<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_mirror', function (Blueprint $table) {
            // Meaningful for type=variable products: whether wholesale buyers must
            // take a proportional spread across every size/color ("فروش به صورت جور")
            // rather than cherry-picking. Informational only — doesn't gate anything.
            $table->boolean('sold_as_set')->default(true)->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_mirror', function (Blueprint $table) {
            $table->dropColumn('sold_as_set');
        });
    }
};
