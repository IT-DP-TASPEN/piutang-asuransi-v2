<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'gl_to_gl_early_termination_top_up_unique';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('gl_to_gl_transactions', function (Blueprint $table) {
            $table->string('purpose')->default('ckpn_journal')->after('id');
            $table->foreignId('insurance_receivable_id')
                ->nullable()
                ->after('ckpn_workpaper_id')
                ->constrained('insurance_receivables')
                ->nullOnDelete();
        });

        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement(sprintf(
                "CREATE UNIQUE INDEX %s ON gl_to_gl_transactions (insurance_receivable_id) WHERE purpose = 'early_termination_repayment_top_up' AND insurance_receivable_id IS NOT NULL",
                self::UNIQUE_INDEX,
            ));

            return;
        }

        Schema::table('gl_to_gl_transactions', function (Blueprint $table) {
            $table->unique(['purpose', 'insurance_receivable_id'], self::UNIQUE_INDEX);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.self::UNIQUE_INDEX);
        } else {
            Schema::table('gl_to_gl_transactions', function (Blueprint $table) {
                $table->dropUnique(self::UNIQUE_INDEX);
            });
        }

        Schema::table('gl_to_gl_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('insurance_receivable_id');
            $table->dropColumn('purpose');
        });
    }
};
