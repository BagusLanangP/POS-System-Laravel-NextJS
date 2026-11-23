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
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();

            // Money in Rupiah (bigint, no decimals)
            // Starting cash in the drawer, entered by the cashier when opening
            $table->bigInteger('opening_cash');
            // Cash that should be in the drawer: opening_cash + cash received - change.
            // Set to opening_cash on open, kept up to date by sales and voids.
            $table->bigInteger('expected_cash');
            // Physical cash counted by the cashier when closing
            $table->bigInteger('counted_cash')->nullable();
            // Always counted_cash - expected_cash, computed by the database (NULL while open)
            $table->bigInteger('difference')->nullable()->storedAs('counted_cash - expected_cash');

            $table->enum('status', ['open', 'closed'])->default('open');
            $table->text('closing_note')->nullable();
            $table->timestampsTz();

            $table->index(['outlet_id', 'opened_at']);
        });

        DB::statement('ALTER TABLE shifts ADD CONSTRAINT shifts_cash_check CHECK (
            opening_cash >= 0 AND (counted_cash IS NULL OR counted_cash >= 0)
        )');

        // An open shift has no close data; a closed shift must have it
        DB::statement('ALTER TABLE shifts ADD CONSTRAINT shifts_close_data_check CHECK (
            (status = \'open\' AND closed_at IS NULL AND counted_cash IS NULL)
            OR (status = \'closed\' AND closed_at IS NOT NULL AND counted_cash IS NOT NULL AND closed_at >= opened_at)
        )');

        // A cashier can have only one open shift at a time
        DB::statement('CREATE UNIQUE INDEX shifts_one_open_per_cashier ON shifts (cashier_id) WHERE status = \'open\'');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
