<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_transaction_references', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('service_action');
            $table->string('operation_key')->nullable();
            $table->string('source_type')->nullable();
            $table->string('source_table')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['operation_key', 'service_action']);
            $table->index(['source_table', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_transaction_references');
    }
};
