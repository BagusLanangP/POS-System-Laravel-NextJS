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
        Schema::create('stock_balances', function (Blueprint $table) {
            // Current stock per outlet and product: a cache of SUM(stock_movements.qty_change).
            // Updated in the same DB transaction as each movement (SELECT ... FOR UPDATE,
            // locked in product_id order) and can always be rebuilt from the ledger.
            $table->foreignId('outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            // May go negative on purpose (doc.md open decision #1): a sale is not blocked
            // when physical stock arrived before the goods receipt was entered
            $table->integer('qty')->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['outlet_id', 'product_id']);
            // Product-wide lookups across outlets
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }
};
