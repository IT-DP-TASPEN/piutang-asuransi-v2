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
        Schema::create('insurance_receivables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_office_id')->constrained()->restrictOnDelete();
            $table->string('branch_code', 3);
            $table->string('cif_no')->nullable();
            $table->string('cif_no_alt')->nullable();
            $table->string('loan_account_number');
            $table->string('alt_number')->nullable();
            $table->string('customer_name')->nullable();
            $table->date('date_of_death')->nullable();
            $table->string('death_document_condition')->nullable();
            $table->foreignId('insurance_company_id')->constrained()->restrictOnDelete();
            $table->foreignId('claim_status_id')->constrained()->restrictOnDelete();
            $table->decimal('credit_limit', 20, 2)->nullable();
            $table->decimal('loan_outstanding', 20, 2)->nullable();
            $table->string('collectability')->nullable();
            $table->integer('dpd')->nullable();
            $table->string('product_id')->nullable();
            $table->string('product_name')->nullable();
            $table->string('saving_account_for_loan_repayment')->nullable();
            $table->date('start_period')->nullable();
            $table->date('end_period')->nullable();
            $table->date('receivable_formation_date')->nullable();
            $table->decimal('receivable_amount', 20, 2)->nullable();
            $table->decimal('remaining_receivable_amount', 20, 2)->default(0);
            $table->string('workflow_status')->default('draft');
            $table->string('stage')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_office_id', 'workflow_status']);
            $table->index('branch_code');
            $table->index('loan_account_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('insurance_receivables');
    }
};
