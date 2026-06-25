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
        Schema::create('early_termination_balance_inquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurance_receivable_id');
            $table->foreignId('api_integration_log_id')->nullable();
            $table->string('saving_account_number');
            $table->decimal('loan_outstanding_amount', 20, 2);
            $table->decimal('available_balance', 20, 2)->nullable();
            $table->decimal('required_top_up_amount', 20, 2)->nullable();
            $table->string('response_code')->nullable();
            $table->string('response_description')->nullable();
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->foreignId('requested_by')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('insurance_receivable_id', 'et_balance_inquiries_receivable_id_index');
            $table->index('api_integration_log_id', 'et_balance_inquiries_api_log_id_index');
            $table->index('requested_by', 'et_balance_inquiries_requested_by_index');
            $table->index('requested_at');
            $table->index(['insurance_receivable_id', 'id'], 'et_balance_inquiries_receivable_id_id_index');

            $table->foreign('insurance_receivable_id', 'et_balance_inquiries_receivable_fk')
                ->references('id')
                ->on('insurance_receivables')
                ->cascadeOnDelete();
            $table->foreign('api_integration_log_id', 'et_balance_inquiries_api_log_fk')
                ->references('id')
                ->on('api_integration_logs')
                ->nullOnDelete();
            $table->foreign('requested_by', 'et_balance_inquiries_requested_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        Schema::table('gl_to_gl_transactions', function (Blueprint $table) {
            $table->foreignId('early_termination_balance_inquiry_id')
                ->nullable()
                ->after('insurance_receivable_id');

            $table->index('early_termination_balance_inquiry_id', 'gl_to_gl_et_balance_inquiry_id_index');

            $table->foreign('early_termination_balance_inquiry_id', 'gl_to_gl_et_balance_inquiry_fk')
                ->references('id')
                ->on('early_termination_balance_inquiries')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gl_to_gl_transactions', function (Blueprint $table) {
            $table->dropForeign('gl_to_gl_et_balance_inquiry_fk');
            $table->dropIndex('gl_to_gl_et_balance_inquiry_id_index');
            $table->dropColumn('early_termination_balance_inquiry_id');
        });

        Schema::dropIfExists('early_termination_balance_inquiries');
    }
};
