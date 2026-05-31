<?php

namespace Database\Seeders;

use App\Models\InsuranceCompany;
use Illuminate\Database\Seeder;

class InsuranceCompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $companies = [
            'SDI' => '0',
            'ASEI' => '0',
            'BPJS TK' => '0',
            'HEKSA' => '0.5',
            'GIB RELIANCE' => '0.5',
            'AA PIALANG' => '0.5',
            'MPM' => '0.5',
            'ABB' => '50',
            'NASIONAL LIFE' => '50',
            'TASPEN LIFE' => '100',
        ];

        foreach ($companies as $name => $ckpnWeight) {
            InsuranceCompany::query()->updateOrCreate(
                ['name' => $name],
                [
                    'code' => null,
                    'ckpn_weight' => $ckpnWeight,
                    'sla_description' => null,
                    'is_active' => true,
                ],
            );
        }
    }
}
