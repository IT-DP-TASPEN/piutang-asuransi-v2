<?php

namespace Database\Seeders;

use App\Models\CkpnAgeBucket;
use Illuminate\Database\Seeder;

class CkpnAgeBucketSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $buckets = [
            [
                'name' => '1 - 6 bulan',
                'min_days' => 0,
                'max_days' => 180,
                'ckpn_weight' => '0.5',
            ],
            [
                'name' => '7 - 12 bulan',
                'min_days' => 181,
                'max_days' => 365,
                'ckpn_weight' => '0.5',
            ],
            [
                'name' => '> 12 bulan',
                'min_days' => 366,
                'max_days' => null,
                'ckpn_weight' => '100',
            ],
        ];

        foreach ($buckets as $bucket) {
            CkpnAgeBucket::query()->updateOrCreate(
                ['name' => $bucket['name']],
                [...$bucket, 'is_active' => true],
            );
        }
    }
}
