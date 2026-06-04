<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_receivable_stage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurance_receivable_id')->constrained()->cascadeOnDelete();
            $table->string('from_stage')->nullable();
            $table->string('to_stage')->nullable();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->string('event');
            $table->text('description')->nullable();
            $table->string('triggered_by_type')->default('system');
            $table->unsignedBigInteger('triggered_by_id')->nullable();
            $table->foreignId('approval_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('api_integration_log_id')->nullable()->constrained()->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['insurance_receivable_id', 'created_at'], 'receivable_created_at_index');
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_receivable_stage_logs');
    }
};
