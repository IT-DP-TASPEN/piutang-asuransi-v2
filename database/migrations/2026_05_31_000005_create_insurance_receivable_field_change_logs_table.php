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
        Schema::create('insurance_receivable_field_change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurance_receivable_id')->constrained()->cascadeOnDelete();
            $table->string('field_name');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('insurance_receivable_field_change_logs');
    }
};
