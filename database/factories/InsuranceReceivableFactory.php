<?php

namespace Database\Factories;

use App\Models\BranchOffice;
use App\Models\ClaimStatus;
use App\Models\InsuranceCompany;
use App\Models\InsuranceReceivable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InsuranceReceivable>
 */
class InsuranceReceivableFactory extends Factory
{
    public function configure(): static
    {
        return $this
            ->afterMaking(function (InsuranceReceivable $receivable): void {
                if ($receivable->receivable_amount === null) {
                    return;
                }

                if (! in_array((string) $receivable->remaining_receivable_amount, ['', '0', '0.00'], true)) {
                    return;
                }

                $receivable->remaining_receivable_amount = $receivable->receivable_amount;
            });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $branchOffice = BranchOffice::query()->firstOrCreate(
            ['branch_code' => '001'],
            ['branch_name' => 'Cabang 001', 'is_active' => true],
        );
        $insuranceCompany = InsuranceCompany::query()->firstOrCreate(
            ['name' => 'SDI'],
            ['code' => null, 'claim_type' => InsuranceCompany::CLAIM_TYPE_AJK, 'ckpn_weight' => '0', 'sla_description' => null, 'is_active' => true],
        );
        $claimStatus = ClaimStatus::query()->firstOrCreate(
            ['code' => ClaimStatus::DEFAULT_CODE],
            ['name' => 'On proses', 'ckpn_weight' => '0', 'is_default' => true, 'is_terminal' => false, 'is_active' => true],
        );

        return [
            'branch_office_id' => $branchOffice->id,
            'branch_code' => $branchOffice->branch_code,
            'loan_account_number' => fake()->numerify('301#############'),
            'date_of_death' => fake()->date(),
            'insurance_company_id' => $insuranceCompany->id,
            'claim_status_id' => $claimStatus->id,
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
            'created_by' => User::factory(),
        ];
    }
}
