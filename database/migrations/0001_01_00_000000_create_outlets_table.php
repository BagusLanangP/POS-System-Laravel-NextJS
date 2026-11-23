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
        Schema::create('outlets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 100);
            // e.g. 2015 (the 2nd argument of integer() means auto-increment, not length)
            $table->smallInteger('year_established')->nullable();
            // String, not integer: keeps the leading 0 and allows "+62" / "(0361)"
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->text('description')->nullable();
            $table->string('timezone', 50)->default('Asia/Makassar');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outlets');
    }
};
