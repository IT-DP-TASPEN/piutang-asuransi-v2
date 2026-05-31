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
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('approvable_type');
            $table->unsignedBigInteger('approvable_id');
            $table->string('workflow_code');
            $table->string('status')->default('draft');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('final_approved_at')->nullable();
            $table->timestamps();

            $table->index(['approvable_type', 'approvable_id']);
            $table->index(['workflow_code', 'status']);
        });

        Schema::create('approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->integer('step_order');
            $table->string('role_name')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['approval_request_id', 'status']);
            $table->unique(['approval_request_id', 'step_order']);
        });

        Schema::create('approval_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_logs');
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approval_requests');
    }
};
