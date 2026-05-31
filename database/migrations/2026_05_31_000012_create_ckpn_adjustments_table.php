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
        Schema::create('ckpn_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurance_receivable_id')->constrained()->restrictOnDelete();
            $table->foreignId('ckpn_workpaper_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ckpn_workpaper_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('adjustment_type');
            $table->decimal('original_rate', 8, 4)->nullable();
            $table->decimal('adjusted_rate', 8, 4)->nullable();
            $table->decimal('original_amount', 20, 2)->nullable();
            $table->decimal('adjusted_amount', 20, 2)->nullable();
            $table->text('reason');
            $table->string('status')->default('draft');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['ckpn_workpaper_id', 'status']);
            $table->index(['insurance_receivable_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ckpn_adjustments');
    }
};
