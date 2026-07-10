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
        $rules = [
            [
                'code' => 'average_three_factors',
                'name' => 'Average Three Factors',
                'strategy_class' => 'App\\Services\\Ckpn\\Strategies\\AverageThreeFactorsStrategy',
                'description' => null,
                'is_active' => true,
            ],
        ];

        foreach ($rules as $rule) {
            CkpnCalculationRule::query()->updateOrCreate(
                ['code' => $rule['code']],
                $rule,
            );
        }
    }
}
