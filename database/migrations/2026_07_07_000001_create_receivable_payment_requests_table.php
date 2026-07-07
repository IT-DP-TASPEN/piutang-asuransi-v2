<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivable_payment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurance_receivable_id')->constrained()->restrictOnDelete();
            $table->foreignId('approval_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('gl_to_gl_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 20, 2);
            $table->string('payment_source');
            $table->string('saving_account_number')->nullable();
            $table->json('saving_account_snapshot')->nullable();
            $table->string('status');
            $table->text('maker_notes')->nullable();
            $table->text('approver_notes')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('gl_executed_at')->nullable();
            $table->foreignId('receivable_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['insurance_receivable_id', 'status']);
            $table->index(['approval_request_id', 'status']);
            $table->index(['requested_by', 'submitted_at']);
        });

        Schema::table('receivable_payments', function (Blueprint $table) {
            $table->foreignId('receivable_payment_request_id')
                ->nullable()
                ->after('insurance_receivable_id')
                ->constrained()
                ->nullOnDelete();
        });

        Schema::table('gl_to_gl_transactions', function (Blueprint $table) {
            $table->foreignId('receivable_payment_request_id')
                ->nullable()
                ->after('early_termination_balance_inquiry_id')
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('receivable_payment_id')
                ->nullable()
                ->after('receivable_payment_request_id')
                ->constrained()
                ->nullOnDelete();

            $table->unique('receivable_payment_request_id', 'gl_to_gl_receivable_payment_request_unique');
        });
    }

    public function down(): void
    {
        Schema::table('gl_to_gl_transactions', function (Blueprint $table) {
            $table->dropUnique('gl_to_gl_receivable_payment_request_unique');
            $table->dropConstrainedForeignId('receivable_payment_id');
            $table->dropConstrainedForeignId('receivable_payment_request_id');
        });

        Schema::table('receivable_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('receivable_payment_request_id');
        });

        Schema::dropIfExists('receivable_payment_requests');
    }
};
