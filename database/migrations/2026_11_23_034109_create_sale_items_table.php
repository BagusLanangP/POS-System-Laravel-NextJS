<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            // Items are part of the sale (sales are voided, never deleted)
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            // Snapshot at the time of sale: later changes to the product
            // (rename, new barcode, new price) must not change old receipts
            $table->string('product_name', 150);
            // Barcode that was scanned; NULL when the item was picked manually
            $table->string('barcode', 50)->nullable();

            // Integer quantity (see doc.md open decision #3 for weighed goods)
            $table->integer('qty');

            // Money in Rupiah (bigint, no decimals)
            $table->bigInteger('unit_price');                  // from outlet_prices
            $table->bigInteger('discount_amount')->default(0); // total discount for this line
            // Promo that produced the discount; NULL when there was none
            $table->foreignId('promo_id')->nullable()->constrained('promos')->restrictOnDelete();
            $table->bigInteger('line_total');                  // unit_price × qty - discount_amount

            $table->timestampsTz();

            $table->index('sale_id');
            $table->index('product_id');
        });

        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_amounts_check CHECK (
            qty > 0
            AND unit_price >= 0
            AND discount_amount >= 0
            AND discount_amount <= unit_price * qty
            AND line_total = unit_price * qty - discount_amount
        )');

        // A discount must come from a promo
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_promo_check CHECK (
            promo_id IS NOT NULL OR discount_amount = 0
        )');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
