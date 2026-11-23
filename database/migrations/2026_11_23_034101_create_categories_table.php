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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            // nullable: top-level category; filled: sub-category of parent
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->timestampsTz();

            // No duplicate names under the same parent (NULL parent counts as one group)
            $table->unique(['parent_id', 'name'])->nullsNotDistinct();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
