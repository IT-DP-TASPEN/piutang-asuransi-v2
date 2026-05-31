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
        Schema::create('generated_exports', function (Blueprint $table) {
            $table->id();
            $table->string('exportable_type');
            $table->unsignedBigInteger('exportable_id');
            $table->string('export_type');
            $table->string('file_path')->nullable();
            $table->string('status');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['exportable_type', 'exportable_id']);
            $table->index(['export_type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generated_exports');
    }
};
