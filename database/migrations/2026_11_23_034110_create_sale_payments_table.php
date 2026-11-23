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
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            // Payments are part of the sale (sales are voided, never deleted)
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            // qris and edc are simulated in the MVP
            $table->enum('method', ['cash', 'qris', 'edc']);
            // Rupiah handed over with this method (for cash: before change is given)
            $table->bigInteger('amount');
            // Transaction reference from the QRIS app / EDC slip; NULL for cash
            $table->string('reference_no', 100)->nullable();
            $table->timestampsTz();

            $table->index('sale_id');
        });

        DB::statement('ALTER TABLE sale_payments ADD CONSTRAINT sale_payments_amount_check CHECK (amount > 0)');

        // Non-cash payments must carry a reference so they can be traced
        DB::statement('ALTER TABLE sale_payments ADD CONSTRAINT sale_payments_reference_check CHECK (
            method = \'cash\' OR reference_no IS NOT NULL
        )');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};
