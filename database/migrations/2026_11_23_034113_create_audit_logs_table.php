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
        // Record of sensitive actions (void, price change, stock adjustment, failed PIN, ...).
        // Append-only like stock_movements: rows are never updated or deleted.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Who did it; NULL for system jobs or unauthenticated attempts (e.g. failed login)
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            // What happened, e.g. "sale.voided", "outlet_price.updated", "void.pin_failed"
            $table->string('action', 100);
            // What it happened to, e.g. ('sale', 42)
            $table->string('subject_type', 50)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            // Details, e.g. {"reason": "...", "approved_by": 7} or {"old": {...}, "new": {...}}
            $table->jsonb('payload')->nullable();
            $table->ipAddress('ip')->nullable();
            // No updated_at: rows never change
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        // OR REPLACE: migrate:fresh drops tables but not functions
        DB::statement('CREATE OR REPLACE FUNCTION audit_logs_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION \'audit_logs is append-only: % is not allowed\', TG_OP;
            END;
        $$ LANGUAGE plpgsql');

        DB::statement('CREATE TRIGGER audit_logs_no_update_delete
            BEFORE UPDATE OR DELETE ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION audit_logs_append_only()');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        DB::statement('DROP FUNCTION IF EXISTS audit_logs_append_only()');
    }
};
