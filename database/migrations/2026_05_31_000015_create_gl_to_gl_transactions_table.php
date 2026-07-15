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
        Schema::create('gl_to_gl_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ckpn_journal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ckpn_workpaper_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('attempt_no')->nullable();
            $table->string('reference_number')->nullable()->unique();
            $table->string('receipt_number')->nullable()->unique();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('response_code')->nullable();
            $table->string('response_description')->nullable();
            $table->string('status')->nullable();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['ckpn_journal_id', 'status']);
            $table->index(['ckpn_workpaper_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gl_to_gl_transactions');
    }
};
