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
        // Needed so the EXCLUDE constraint can mix "=" (product, outlet) with "&&" (time range)
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('promos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            // nullable: promo applies to all outlets
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->restrictOnDelete();
            // percent: value = 10 means 10% off; nominal: value = 2000 means Rp2.000 off
            $table->enum('type', ['percent', 'nominal']);
            $table->bigInteger('value');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE promos ADD CONSTRAINT promos_value_check CHECK (
            value > 0 AND (type <> \'percent\' OR value <= 100)
        )');

        DB::statement('ALTER TABLE promos ADD CONSTRAINT promos_period_check CHECK (ends_at > starts_at)');

        // Two active promos for the same product and the same outlet scope may not overlap in time.
        // COALESCE(outlet_id, 0) makes "all outlets" promos collide with each other too
        // (plain "=" would treat NULL as never equal).
        DB::statement('ALTER TABLE promos ADD CONSTRAINT promos_no_overlap EXCLUDE USING gist (
            product_id WITH =,
            (COALESCE(outlet_id, 0)) WITH =,
            tstzrange(starts_at, ends_at, \'[)\') WITH &&
        ) WHERE (is_active)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promos');
    }
};
