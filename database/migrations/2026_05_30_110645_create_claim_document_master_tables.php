<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('accepted_file_types')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('claim_document_requirements', function (Blueprint $table) {
            $table->id();
            $table->string('claim_type');
            $table->foreignId('claim_document_type_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_required')->default(true);
            $table->boolean('is_conditional')->default(false);
            $table->string('condition_key')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['claim_type', 'claim_document_type_id'], 'claim_document_requirement_unique');
            $table->index(['claim_type', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_document_requirements');
        Schema::dropIfExists('claim_document_types');
    }
};
