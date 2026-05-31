<?php

namespace Database\Seeders;

use App\Models\ClaimStatus;
use Illuminate\Database\Seeder;

class ClaimStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $statuses = [
            [
                'code' => ClaimStatus::DEFAULT_CODE,
                'name' => 'On proses',
                'ckpn_weight' => '0',
                'is_default' => true,
                'is_terminal' => false,
            ],
            [
                'code' => 'approved',
                'name' => 'Approved',
                'ckpn_weight' => '0',
                'is_default' => false,
                'is_terminal' => false,
            ],
            [
                'code' => 'reject_loss',
                'name' => 'Reject Loss',
                'ckpn_weight' => '100',
                'is_default' => false,
                'is_terminal' => true,
            ],
            [
                'code' => 'reject_installment_heir',
                'name' => 'Reject - Cicil Ahli Waris',
                'ckpn_weight' => '0.5',
                'is_default' => false,
                'is_terminal' => false,
            ],
        ];

        foreach ($statuses as $status) {
            ClaimStatus::query()->updateOrCreate(
                ['code' => $status['code']],
                [...$status, 'is_active' => true],
            );
        }
    }
}
