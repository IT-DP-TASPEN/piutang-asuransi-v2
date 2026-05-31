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
        Schema::create('receivable_formation_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurance_receivable_id')->constrained()->cascadeOnDelete();
            $table->date('journal_date');
            $table->decimal('amount', 20, 2);
            $table->string('debit_account')->nullable();
            $table->string('credit_account')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receivable_formation_journals');
    }
};
