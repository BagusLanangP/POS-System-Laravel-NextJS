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
        Schema::create('receipt_sequences', function (Blueprint $table) {
            // One counter per outlet per business day; no surrogate id needed
            $table->foreignId('outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->date('business_date');
            // Last receipt number handed out for this outlet and day
            $table->integer('last_number');

            $table->primary(['outlet_id', 'business_date']);
        });

        // Incremented atomically by ReceiptNumberService:
        //   INSERT ... VALUES (?, ?, 1)
        //   ON CONFLICT (outlet_id, business_date)
        //   DO UPDATE SET last_number = receipt_sequences.last_number + 1
        //   RETURNING last_number;
        DB::statement('ALTER TABLE receipt_sequences ADD CONSTRAINT receipt_sequences_last_number_check CHECK (last_number > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receipt_sequences');
    }
};
