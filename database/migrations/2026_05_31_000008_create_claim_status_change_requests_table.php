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
        Schema::create('claim_status_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurance_receivable_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_claim_status_id')->constrained('claim_statuses')->restrictOnDelete();
            $table->foreignId('to_claim_status_id')->constrained('claim_statuses')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->string('supporting_document_path')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();

            $table->index(['insurance_receivable_id', 'status']);
            $table->index(['from_claim_status_id', 'to_claim_status_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('claim_status_change_requests');
    }
};
