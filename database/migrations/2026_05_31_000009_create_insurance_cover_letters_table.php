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
        Schema::create('insurance_cover_letters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurance_receivable_id')->constrained()->cascadeOnDelete();
            $table->string('letter_number')->nullable();
            $table->date('letter_date')->nullable();
            $table->foreignId('insurance_company_id')->constrained()->restrictOnDelete();
            $table->string('recipient_name')->nullable();
            $table->string('subject')->nullable();
            $table->longText('body')->nullable();
            $table->string('generated_file_path')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['insurance_receivable_id', 'status']);
            $table->index(['insurance_company_id', 'letter_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('insurance_cover_letters');
    }
};
