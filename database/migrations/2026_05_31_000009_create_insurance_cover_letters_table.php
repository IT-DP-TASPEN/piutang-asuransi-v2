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
            $table->string('claim_type');
            $table->string('letter_number')->nullable()->unique();
            $table->unsignedBigInteger('sequence_base');
            $table->unsignedBigInteger('sequence_number')->nullable()->unique();
            $table->date('letter_date');
            $table->string('template_key');
            $table->foreignId('insurance_company_id')->constrained()->restrictOnDelete();
            $table->string('recipient_name')->nullable();
            $table->text('recipient_address')->nullable();
            $table->string('subject')->nullable();
            $table->longText('rendered_html')->nullable();
            $table->string('generated_file_path')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['insurance_receivable_id', 'status']);
            $table->index(['insurance_company_id', 'letter_date']);
            $table->unique(['insurance_receivable_id', 'claim_type'], 'receivable_claim_type_letter_unique');
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
