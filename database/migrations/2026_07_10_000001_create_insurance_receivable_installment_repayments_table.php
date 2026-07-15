<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_receivable_installment_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurance_receivable_id');
            $table->foreignId('api_integration_log_id')->nullable();
            $table->foreignId('balance_api_integration_log_id')->nullable();
            $table->foreignId('post_repayment_inquiry_api_integration_log_id')->nullable();
            $table->string('reference_number')->nullable();
            $table->string('account_number');
            $table->string('alt_number')->nullable();
            $table->string('branch_code');
            $table->string('saving_account_number')->nullable();
            $table->decimal('installment_amount', 20, 2);
            $table->decimal('loan_outstanding_before', 20, 2);
            $table->decimal('loan_outstanding_after', 20, 2)->nullable();
            $table->date('next_due_date')->nullable();
            $table->date('date_of_death');
            $table->string('status');
            $table->string('response_code')->nullable();
            $table->string('response_description')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('last_error_message')->nullable();
            $table->string('resolution_outcome')->nullable();
            $table->json('resolution_payload')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique('insurance_receivable_id', 'ir_installment_repayments_receivable_unique');
            $table->index(['status', 'updated_at'], 'ir_installment_repayments_status_updated_index');

            $table->foreign('insurance_receivable_id', 'ir_inst_repay_receivable_fk')
                ->references('id')
                ->on('insurance_receivables')
                ->cascadeOnDelete();
            $table->foreign('api_integration_log_id', 'ir_inst_repay_api_log_fk')
                ->references('id')
                ->on('api_integration_logs')
                ->nullOnDelete();
            $table->foreign('balance_api_integration_log_id', 'ir_inst_repay_balance_log_fk')
                ->references('id')
                ->on('api_integration_logs')
                ->nullOnDelete();
            $table->foreign('post_repayment_inquiry_api_integration_log_id', 'ir_inst_repay_post_log_fk')
                ->references('id')
                ->on('api_integration_logs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_receivable_installment_repayments');
    }
};
