<?php

namespace Database\Factories;

use App\Models\BranchOffice;
use App\Models\ClaimStatus;
use App\Models\InsuranceCompany;
use App\Models\LegacyReceivable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegacyReceivable>
 */
class LegacyReceivableFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = '1000000.00';
        $branchOffice = BranchOffice::query()->firstOrCreate(
            ['branch_code' => '001'],
            ['branch_name' => 'Cabang 001', 'is_active' => true],
        );
        $insuranceCompany = InsuranceCompany::query()->firstOrCreate(
            ['name' => 'VICTORIA ALIFE'],
            [
                'code' => null,
                'claim_type' => InsuranceCompany::CLAIM_TYPE_AJK,
                'legal_name' => 'PT VICTORIA ALIFE INDONESIA',
                'letter_recipient_name' => 'PT SINERGI DUTA INSURANCE BROKERS',
                'letter_recipient_address' => 'Botany Hills, Fatmawati City Center Soho No.26, Jl. Fatmawati Raya, Jakarta Selatan 12430',
                'ckpn_weight' => '0',
                'sla_description' => null,
                'is_active' => true,
            ],
        );
        $claimStatus = ClaimStatus::query()->firstOrCreate(
            ['code' => ClaimStatus::DEFAULT_CODE],
            ['name' => 'On proses', 'ckpn_weight' => '0', 'is_default' => true, 'is_terminal' => false, 'is_active' => true],
        );

        return [
            'cif' => $this->faker->numerify('CIF####'),
            'customer_name' => $this->faker->name(),
            'loan_account_number' => $this->faker->numerify('301#############'),
            'loan_alt_account_number' => null,
            'branch_office_id' => $branchOffice->id,
            'loan_outstanding' => $amount,
            'insurance_company_id' => $insuranceCompany->id,
            'date_of_death' => now()->subMonths(3)->toDateString(),
            'receivable_formation_date' => now()->subMonths(2)->toDateString(),
            'original_receivable_amount' => $amount,
            'remaining_receivable_amount' => $amount,
            'claim_status_id' => $claimStatus->id,
            'created_by' => null,
        ];
    }
}
