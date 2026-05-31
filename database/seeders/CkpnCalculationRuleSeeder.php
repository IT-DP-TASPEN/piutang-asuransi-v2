<?php

namespace Database\Seeders;

use App\Models\CkpnCalculationRule;
use Illuminate\Database\Seeder;

class CkpnCalculationRuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        CkpnCalculationRule::query()->updateOrCreate(
            ['code' => 'average_three_factors_with_reject_loss_override'],
            [
                'name' => 'Average Three Factors With Reject Loss Override',
                'strategy_class' => 'App\\Services\\Ckpn\\Strategies\\AverageThreeFactorsWithRejectLossOverrideStrategy',
                'description' => null,
                'is_active' => true,
            ],
        );
    }
}
