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
                'name' => ClaimStatus::LABELS[ClaimStatus::ON_PROCESS_CODE],
                'is_default' => true,
                'is_terminal' => false,
            ],
            [
                'code' => ClaimStatus::APPROVED_CODE,
                'name' => ClaimStatus::LABELS[ClaimStatus::APPROVED_CODE],
                'is_default' => false,
                'is_terminal' => true,
            ],
            [
                'code' => ClaimStatus::REJECTED_CODE,
                'name' => ClaimStatus::LABELS[ClaimStatus::REJECTED_CODE],
                'is_default' => false,
                'is_terminal' => true,
            ],
        ];

        ClaimStatus::query()
            ->whereNotIn('code', ClaimStatus::DECISION_CODES)
            ->delete();

        foreach ($statuses as $status) {
            ClaimStatus::query()->updateOrCreate(
                ['code' => $status['code']],
                [...$status, 'is_active' => true],
            );
        }
    }
}
