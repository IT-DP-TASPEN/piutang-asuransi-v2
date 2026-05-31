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
        Schema::create('ckpn_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ckpn_workpaper_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_office_id')->nullable()->constrained()->nullOnDelete();
            $table->date('journal_date');
            $table->decimal('total_amount', 20, 2);
            $table->string('debit_account')->nullable();
            $table->string('credit_account')->nullable();
            $table->text('debit_narrative')->nullable();
            $table->text('credit_narrative')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['ckpn_workpaper_id', 'status']);
            $table->index(['branch_office_id', 'journal_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ckpn_journals');
    }
};
