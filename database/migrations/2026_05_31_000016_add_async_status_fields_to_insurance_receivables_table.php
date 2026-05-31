<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insurance_receivables', function (Blueprint $table) {
            $table->string('system_status')->nullable()->after('workflow_status');
            $table->text('last_error_message')->nullable()->after('system_status');
            $table->timestamp('inquiry_completed_at')->nullable()->after('approved_at');
            $table->timestamp('early_termination_executed_at')->nullable()->after('inquiry_completed_at');

            $table->index('system_status');
        });
    }

    public function down(): void
    {
        Schema::table('insurance_receivables', function (Blueprint $table) {
            $table->dropIndex(['system_status']);
            $table->dropColumn([
                'system_status',
                'last_error_message',
                'inquiry_completed_at',
                'early_termination_executed_at',
            ]);
        });
    }
};
