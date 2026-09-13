<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('early_termination_balance_inquiries', function (Blueprint $table) {
            $table->renameColumn('required_top_up_amount', 'balance_shortfall_amount');
        });
    }

    public function down(): void
    {
        Schema::table('early_termination_balance_inquiries', function (Blueprint $table) {
            $table->renameColumn('balance_shortfall_amount', 'required_top_up_amount');
        });
    }
};
