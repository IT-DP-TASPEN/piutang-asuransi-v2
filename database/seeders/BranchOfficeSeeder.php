<?php

namespace Database\Seeders;

use App\Models\BranchOffice;
use Illuminate\Database\Seeder;

class BranchOfficeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (range(0, 8) as $number) {
            $branchCode = str_pad((string) $number, 3, '0', STR_PAD_LEFT);

            BranchOffice::query()->updateOrCreate(
                ['branch_code' => $branchCode],
                ['branch_name' => "Cabang {$branchCode}", 'is_active' => true],
            );
        }
    }
}
