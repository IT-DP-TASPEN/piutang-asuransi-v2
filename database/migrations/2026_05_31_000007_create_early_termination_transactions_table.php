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
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
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
