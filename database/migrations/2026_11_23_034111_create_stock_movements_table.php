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
        // Stock ledger: the source of truth for stock. Append-only, rows are never
        // updated or deleted. stock_balances is a cache rebuilt from this table.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            // sale: sold (-), void: sale cancelled, stock returned (+),
            // receive: goods received (+), initial: opening stock (+),
            // adjustment: stock opname correction (+/-)
            $table->enum('type', ['sale', 'void', 'adjustment', 'initial', 'receive']);
            // Integer quantity, same as sale_items.qty (see doc.md open decision #3)
            $table->integer('qty_change');
            // What caused the movement, e.g. ('sale', 42) for a sale or a void of sale 42
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            // No updated_at: rows never change
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['outlet_id', 'product_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        // Direction of qty_change must match the movement type
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_qty_check CHECK (
            (type = \'sale\' AND qty_change < 0)
            OR (type IN (\'void\', \'receive\', \'initial\') AND qty_change > 0)
            OR (type = \'adjustment\' AND qty_change <> 0)
        )');

        // Sales and voids must point to the sale; adjustments must explain why
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_reference_check CHECK (
            (type NOT IN (\'sale\', \'void\') OR (reference_type IS NOT NULL AND reference_id IS NOT NULL))
            AND (type <> \'adjustment\' OR note IS NOT NULL)
        )');

        // Enforce append-only at the database level
        // OR REPLACE: migrate:fresh drops tables but not functions
        DB::statement('CREATE OR REPLACE FUNCTION stock_movements_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION \'stock_movements is append-only: % is not allowed\', TG_OP;
            END;
        $$ LANGUAGE plpgsql');

        DB::statement('CREATE TRIGGER stock_movements_no_update_delete
            BEFORE UPDATE OR DELETE ON stock_movements
            FOR EACH ROW EXECUTE FUNCTION stock_movements_append_only()');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        DB::statement('DROP FUNCTION IF EXISTS stock_movements_append_only()');
    }
};
