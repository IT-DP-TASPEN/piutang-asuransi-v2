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
        Schema::create('legacy_receivables', function (Blueprint $table) {
            $table->id();
            $table->string('cif');
            $table->string('customer_name');
            $table->string('loan_account_number');
            $table->string('loan_alt_account_number')->nullable();
            $table->foreignId('branch_office_id')->constrained()->restrictOnDelete();
            $table->decimal('loan_outstanding', 20, 2);
            $table->foreignId('insurance_company_id')->constrained()->restrictOnDelete();
            $table->date('date_of_death');
            $table->date('receivable_formation_date')->nullable();
            $table->decimal('original_receivable_amount', 20, 2);
            $table->decimal('remaining_receivable_amount', 20, 2);
            $table->foreignId('claim_status_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_office_id', 'claim_status_id']);
            $table->index('loan_account_number');
            $table->index('cif');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legacy_receivables');
    }
};
