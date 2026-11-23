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
        Schema::create('outlet_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            // Selling price in Rupiah (bigint, no decimals), VAT included
            $table->bigInteger('price');
            $table->timestampsTz();

            // One price per product per outlet; a product without a row here
            // cannot be sold at that outlet
            $table->unique(['outlet_id', 'product_id']);
            // Product-wide lookups across outlets
            $table->index('product_id');
        });

        DB::statement('ALTER TABLE outlet_prices ADD CONSTRAINT outlet_prices_price_check CHECK (price > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outlet_prices');
    }
};
