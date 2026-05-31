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
        Schema::create('ckpn_workpapers', function (Blueprint $table) {
            $table->id();
            $table->date('period');
            $table->foreignId('branch_office_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->decimal('total_receivable_amount', 20, 2)->default(0);
            $table->decimal('total_ckpn_amount', 20, 2)->default(0);
            $table->timestamps();

            $table->index(['period', 'branch_office_id']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ckpn_workpapers');
    }
};
