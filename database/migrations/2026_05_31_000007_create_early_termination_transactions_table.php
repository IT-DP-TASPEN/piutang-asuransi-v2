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
        Schema::create('early_termination_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurance_receivable_id')->constrained()->cascadeOnDelete();
            $table->string('operation_key')->nullable();
            $table->unsignedInteger('attempt_no')->nullable();
            $table->string('trx_reference')->unique();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('response_code')->nullable();
            $table->string('response_description')->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('journal_id')->nullable();
            $table->string('core_trx_reference')->nullable();
            $table->string('alternate_number')->nullable();
            $table->string('status')->nullable();
            $table->string('resolution_status')->nullable();
            $table->string('resolution_outcome')->nullable();
            $table->text('resolution_reason')->nullable();
            $table->json('resolution_payload')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->unique(['operation_key', 'attempt_no'], 'et_transactions_operation_attempt_unique');
            $table->index(['insurance_receivable_id', 'operation_key', 'status'], 'et_transactions_operation_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('early_termination_transactions');
    }
};
