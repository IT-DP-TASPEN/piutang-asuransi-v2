<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_receivable_installment_repayment_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('insurance_receivable_installment_repayment_id');
            $table->unsignedBigInteger('api_integration_log_id')->nullable();
            $table->unsignedInteger('attempt_no');
            $table->string('reference_number');
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('response_code')->nullable();
            $table->string('response_description')->nullable();
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('executed_by')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['insurance_receivable_installment_repayment_id', 'attempt_no'],
                'ir_inst_repay_attempt_operation_unique',
            );
            $table->unique('reference_number', 'ir_inst_repay_attempt_reference_unique');
            $table->index(['status', 'updated_at'], 'ir_inst_repay_attempt_status_index');
            $table->foreign(
                'insurance_receivable_installment_repayment_id',
                'ir_inst_repay_attempt_parent_fk',
            )
                ->references('id')
                ->on('insurance_receivable_installment_repayments')
                ->cascadeOnDelete();
            $table->foreign('api_integration_log_id', 'ir_inst_repay_attempt_api_log_fk')
                ->references('id')
                ->on('api_integration_logs')
                ->nullOnDelete();
            $table->foreign('executed_by', 'ir_inst_repay_attempt_executor_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_receivable_installment_repayment_attempts');
    }
};
