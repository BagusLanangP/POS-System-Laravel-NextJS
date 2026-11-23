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
        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            // Barcodes belong to the product; removed together with it
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // Manufacturer code printed on the package, e.g. EAN-13 "8996001600146".
            // Unique across all products so one scan always resolves to one product.
            $table->string('barcode', 50)->unique();
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();
        });

        // At most one primary barcode per product (PostgreSQL partial unique index)
        DB::statement(
            'CREATE UNIQUE INDEX product_barcodes_one_primary_per_product '
            .'ON product_barcodes (product_id) WHERE is_primary'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_barcodes');
    }
};
