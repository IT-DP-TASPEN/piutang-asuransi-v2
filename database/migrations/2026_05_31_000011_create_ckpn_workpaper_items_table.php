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
        Schema::create('ckpn_workpaper_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ckpn_workpaper_id')->constrained()->cascadeOnDelete();
            $table->string('receivable_type');
            $table->unsignedBigInteger('receivable_id');
            $table->string('branch_code')->nullable();
            $table->string('branch_name')->nullable();
            $table->string('cif_no')->nullable();
            $table->string('loan_account_number')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('insurance_company_name')->nullable();
            $table->string('claim_status_name')->nullable();
            $table->date('receivable_formation_date')->nullable();
            $table->decimal('receivable_amount', 20, 2);
            $table->integer('age_days');
            $table->string('age_bucket_name')->nullable();
            $table->decimal('insurance_company_weight', 8, 4);
            $table->decimal('age_weight', 8, 4);
            $table->decimal('claim_status_weight', 8, 4);
            $table->decimal('calculated_ckpn_rate', 8, 4);
            $table->decimal('calculated_ckpn_amount', 20, 2);
            $table->decimal('adjusted_ckpn_rate', 8, 4)->nullable();
            $table->decimal('adjusted_ckpn_amount', 20, 2)->nullable();
            $table->timestamp('adjustment_applied_at')->nullable();
            $table->foreignId('adjustment_applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('adjustment_reason')->nullable();
            $table->decimal('effective_ckpn_rate', 8, 4);
            $table->decimal('effective_ckpn_amount', 20, 2);
            $table->string('calculation_rule_code');
            $table->text('calculation_explanation')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->index(['receivable_type', 'receivable_id']);
            $table->index(['ckpn_workpaper_id', 'receivable_type']);
            $table->index('branch_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ckpn_workpaper_items');
    }
};
