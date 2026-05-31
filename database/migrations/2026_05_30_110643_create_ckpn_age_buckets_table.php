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
        Schema::create('ckpn_age_buckets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('min_days')->nullable();
            $table->integer('max_days')->nullable();
            $table->decimal('ckpn_weight', 8, 4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ckpn_age_buckets');
    }
};
