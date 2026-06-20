<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('receivable_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legacy_receivable_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('insurance_receivable_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 20, 2);
            $table->date('paid_at');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['legacy_receivable_id', 'paid_at']);
            $table->index(['insurance_receivable_id', 'paid_at']);
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE receivable_payments ADD CONSTRAINT receivable_payments_exactly_one_target_check CHECK ((legacy_receivable_id IS NOT NULL AND insurance_receivable_id IS NULL) OR (legacy_receivable_id IS NULL AND insurance_receivable_id IS NOT NULL))'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receivable_payments');
    }
};
