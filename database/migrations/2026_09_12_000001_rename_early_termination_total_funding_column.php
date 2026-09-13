<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('early_termination_balance_inquiries', function (Blueprint $table) {
            $table->renameColumn('total_shortage_amount', 'total_funding_amount');
        });
    }

    public function down(): void
    {
        Schema::table('early_termination_balance_inquiries', function (Blueprint $table) {
            $table->renameColumn('total_funding_amount', 'total_shortage_amount');
        });
    }
};
