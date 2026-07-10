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
        Schema::table('purchase_invoice_lines', function (Blueprint $table) {
            // Which catalog product this line was actually purchased for (nullable —
            // a line can still be a bare Cost Item with no matching product, e.g.
            // packaging). Lets a later "finalize" of a saved draft know whether to
            // cascade landed cost to a variable product's variations, without
            // needing the original create-request's context.
            $table->foreignId('product_mirror_id')->nullable()->after('cost_item_id')
                ->constrained('product_mirror')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_invoice_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_mirror_id');
        });
    }
};
