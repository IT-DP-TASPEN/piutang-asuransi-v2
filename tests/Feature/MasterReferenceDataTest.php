<?php

namespace Tests\Feature;

use App\Models\BranchOffice;
use App\Models\CkpnCalculationRule;
use App\Models\ClaimStatus;
use App\Models\InsuranceCompany;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MasterReferenceDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_reference_data(): void
    {
        $this->seed();

        $this->assertSame(
            ['000', '001', '002', '003', '004', '005', '006', '007', '008'],
            BranchOffice::query()->orderBy('branch_code')->pluck('branch_code')->all(),
        );

        $this->assertSame('0.5000', InsuranceCompany::query()->where('name', 'HEKSA INSURANCE')->firstOrFail()->ckpn_weight);
        $this->assertSame('50.0000', InsuranceCompany::query()->where('name', 'PT ASURANSI BHAKTI BHAYANGKARA')->firstOrFail()->ckpn_weight);
        $this->assertSame('100.0000', InsuranceCompany::query()->where('name', 'TASPEN LIFE')->firstOrFail()->ckpn_weight);

        $defaultStatus = ClaimStatus::query()->where('is_default', true)->sole();
        $this->assertFalse(Schema::hasColumn('claim_statuses', 'ckpn_weight'));
        $this->assertSame(ClaimStatus::DEFAULT_CODE, $defaultStatus->code);
        $this->assertSame(ClaimStatus::LABELS[ClaimStatus::DEFAULT_CODE], $defaultStatus->name);
        $this->assertSame(
            [ClaimStatus::APPROVED_CODE, ClaimStatus::ON_PROCESS_CODE, ClaimStatus::REJECTED_CODE],
            ClaimStatus::query()->where('is_active', true)->orderBy('code')->pluck('code')->all(),
        );
        $this->assertDatabaseMissing('claim_statuses', ['code' => 'reject_loss']);
        $this->assertDatabaseMissing('claim_statuses', ['code' => 'reject_installment_heir']);
        $this->assertDatabaseMissing('claim_statuses', ['code' => 'installment_insurance']);

        $activeRule = CkpnCalculationRule::query()->where('is_active', true)->sole();
        $this->assertSame('average_three_factors', $activeRule->code);
        $this->assertSame(
            'App\\Services\\Ckpn\\Strategies\\AverageThreeFactorsStrategy',
            $activeRule->strategy_class,
        );

        $testUser = User::query()->where('email', 'test@example.com')->firstOrFail();
        $this->assertSame('000', $testUser->branchOffice->branch_code);
        $this->assertTrue($testUser->hasRole('super_admin'));
    }
}
