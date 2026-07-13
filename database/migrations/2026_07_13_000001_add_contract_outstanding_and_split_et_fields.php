<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropOldEarlyTerminationTopUpUnique();

        Schema::table('api_integration_logs', function (Blueprint $table) {
            $table->unsignedInteger('duration_ms')->nullable()->after('error_message');
            $table->timestamp('completed_at')->nullable()->after('requested_at');
        });

        Schema::table('insurance_receivables', function (Blueprint $table) {
            $table->decimal('contract_outstanding_amount', 20, 2)->nullable()->after('loan_outstanding');
            $table->date('contract_outstanding_requested_as_of')->nullable()->after('contract_outstanding_amount');
            $table->date('contract_outstanding_as_of')->nullable()->after('contract_outstanding_requested_as_of');
            $table->string('contract_outstanding_product_code')->nullable()->after('contract_outstanding_as_of');
            $table->string('contract_outstanding_trx_type')->nullable()->after('contract_outstanding_product_code');
            $table->foreignId('contract_outstanding_api_log_id')
                ->nullable()
                ->after('contract_outstanding_trx_type')
                ->constrained('api_integration_logs')
                ->nullOnDelete();
        });

        Schema::table('gl_to_gl_transactions', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->unique()->after('receipt_number');
            $table->string('resolution_status')->nullable()->after('status');
            $table->text('resolution_reason')->nullable()->after('resolution_status');
            $table->json('resolution_payload')->nullable()->after('resolution_reason');
            $table->text('resolution_notes')->nullable()->after('resolution_payload');
            $table->foreignId('resolved_by')
                ->nullable()
                ->after('resolution_notes')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('resolved_by');

            $table->index(['insurance_receivable_id', 'purpose'], 'gl_to_gl_receivable_purpose_index');
            $table->index('resolution_status', 'gl_to_gl_resolution_status_index');
        });

        Schema::table('early_termination_balance_inquiries', function (Blueprint $table) {
            $table->string('context')->nullable()->after('api_integration_log_id');
            $table->decimal('contract_outstanding_amount', 20, 2)->nullable()->after('required_top_up_amount');
            $table->decimal('spread_amount', 20, 2)->nullable()->after('contract_outstanding_amount');
            $table->decimal('total_shortage_amount', 20, 2)->nullable()->after('spread_amount');
            $table->decimal('lsa_top_up_amount', 20, 2)->nullable()->after('total_shortage_amount');
            $table->decimal('piutang_top_up_amount', 20, 2)->nullable()->after('lsa_top_up_amount');

            $table->index(['insurance_receivable_id', 'context', 'id'], 'et_balance_inquiries_receivable_context_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('early_termination_balance_inquiries', function (Blueprint $table) {
            $table->dropIndex('et_balance_inquiries_receivable_context_id_index');
            $table->dropColumn([
                'context',
                'contract_outstanding_amount',
                'spread_amount',
                'total_shortage_amount',
                'lsa_top_up_amount',
                'piutang_top_up_amount',
            ]);
        });

        Schema::table('gl_to_gl_transactions', function (Blueprint $table) {
            $table->dropIndex('gl_to_gl_receivable_purpose_index');
            $table->dropIndex('gl_to_gl_resolution_status_index');
            $table->dropUnique(['idempotency_key']);
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn([
                'idempotency_key',
                'resolution_status',
                'resolution_reason',
                'resolution_payload',
                'resolution_notes',
                'resolved_at',
            ]);
        });

        Schema::table('insurance_receivables', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_outstanding_api_log_id');
            $table->dropColumn([
                'contract_outstanding_amount',
                'contract_outstanding_requested_as_of',
                'contract_outstanding_as_of',
                'contract_outstanding_product_code',
                'contract_outstanding_trx_type',
            ]);
        });

        Schema::table('api_integration_logs', function (Blueprint $table) {
            $table->dropColumn(['duration_ms', 'completed_at']);
        });

        $this->restoreOldEarlyTerminationTopUpUnique();
    }

    private function dropOldEarlyTerminationTopUpUnique(): void
    {
        $driver = DB::connection()->getDriverName();
        $index = 'gl_to_gl_early_termination_top_up_unique';

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement("DROP INDEX IF EXISTS {$index}");

            return;
        }

        Schema::table('gl_to_gl_transactions', function (Blueprint $table) use ($index) {
            $table->dropUnique($index);
        });
    }

    private function restoreOldEarlyTerminationTopUpUnique(): void
    {
        $driver = DB::connection()->getDriverName();
        $index = 'gl_to_gl_early_termination_top_up_unique';

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement(sprintf(
                "CREATE UNIQUE INDEX %s ON gl_to_gl_transactions (insurance_receivable_id) WHERE purpose = 'early_termination_repayment_top_up' AND insurance_receivable_id IS NOT NULL",
                $index,
            ));

            return;
        }

        Schema::table('gl_to_gl_transactions', function (Blueprint $table) use ($index) {
            $table->unique(['purpose', 'insurance_receivable_id'], $index);
        });
    }
};
