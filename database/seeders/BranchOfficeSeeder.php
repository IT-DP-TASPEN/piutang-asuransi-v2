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
        $branchOffices = [
            ['branch_code' => '000', 'branch_name' => 'Kantor Pusat Manajemen', 'is_active' => true],
            ['branch_code' => '001', 'branch_name' => 'Kantor Pusat Operasional', 'is_active' => true],
            ['branch_code' => '002', 'branch_name' => 'KC Bogor', 'is_active' => true],
            ['branch_code' => '003', 'branch_name' => 'KC Depok', 'is_active' => true],
            ['branch_code' => '004', 'branch_name' => 'KC Tangerang', 'is_active' => true],
            ['branch_code' => '005', 'branch_name' => 'KC Jaktim', 'is_active' => true],
            ['branch_code' => '006', 'branch_name' => 'KC Karawang', 'is_active' => true],
            ['branch_code' => '007', 'branch_name' => 'KC Cikarang', 'is_active' => true],
            ['branch_code' => '008', 'branch_name' => 'KC Purwokerto', 'is_active' => true],
        ];
        foreach ($branchOffices as $branchOffice) {
            BranchOffice::query()->updateOrCreate(
                ['branch_code' => $branchOffice['branch_code']],
                [
                    'branch_name' => $branchOffice['branch_name'],
                    'is_active' => $branchOffice['is_active']
                ]
            );
        }
    }
}
