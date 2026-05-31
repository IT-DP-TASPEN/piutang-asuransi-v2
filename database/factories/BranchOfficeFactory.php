<?php

namespace Database\Factories;

use App\Models\BranchOffice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BranchOffice>
 */
class BranchOfficeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $branchCode = str_pad((string) fake()->unique()->numberBetween(100, 999), 3, '0', STR_PAD_LEFT);

        return [
            'branch_code' => $branchCode,
            'branch_name' => "Cabang {$branchCode}",
            'is_active' => true,
        ];
    }
}
