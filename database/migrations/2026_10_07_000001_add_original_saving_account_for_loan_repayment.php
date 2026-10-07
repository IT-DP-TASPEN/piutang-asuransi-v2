<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insurance_receivables', function (Blueprint $table) {
            $table->string('original_saving_account_for_loan_repayment')->nullable()->after('saving_account_for_loan_repayment');
        });
    }

    public function down(): void
    {
        Schema::table('insurance_receivables', function (Blueprint $table) {
            $table->dropColumn('original_saving_account_for_loan_repayment');
        });
    }
};
