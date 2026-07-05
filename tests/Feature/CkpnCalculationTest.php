<?php

namespace Tests\Feature;

use App\Data\CkpnCalculationInput;
use App\Data\CkpnReceivableCandidate;
use App\Models\CkpnCalculationRule;
use App\Models\ClaimStatus;
use App\Models\InsuranceCompany;
use App\Models\InsuranceReceivable;
use App\Services\Ckpn\CkpnCalculationService;
use Carbon\CarbonImmutable;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\CkpnAgeBucketSeeder;
use Database\Seeders\CkpnCalculationRuleSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CkpnCalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_age_bucket_selection_0_to_180_days(): void
    {
        $this->seedDependencies();
        $receivable = $this->receivable([
            'receivable_formation_date' => '2026-01-01',
            'receivable_amount' => '10000.00',
        ]);

        $result = app(CkpnCalculationService::class)->calculate($this->input($receivable, '2026-06-30'));

        $this->assertSame(180, $result->ageDays);
        $this->assertSame('1 - 6 bulan', $result->ageBucketName);
        $this->assertSame('0.0000', $result->ageWeight);
    }

    public function test_age_bucket_selection_181_to_365_days(): void
    {
        $this->seedDependencies();
        $receivable = $this->receivable([
            'receivable_formation_date' => '2026-01-01',
            'receivable_amount' => '10000.00',
        ]);

        $result = app(CkpnCalculationService::class)->calculate($this->input($receivable, '2026-07-01'));

        $this->assertSame(181, $result->ageDays);
        $this->assertSame('7 - 12 bulan', $result->ageBucketName);
        $this->assertSame('0.5000', $result->ageWeight);
    }

    public function test_age_bucket_selection_over_365_days(): void
    {
        $this->seedDependencies();
        $receivable = $this->receivable([
            'receivable_formation_date' => '2026-01-01',
            'receivable_amount' => '10000.00',
        ]);

        $result = app(CkpnCalculationService::class)->calculate($this->input($receivable, '2027-01-02'));

        $this->assertSame(366, $result->ageDays);
        $this->assertSame('> 12 bulan', $result->ageBucketName);
        $this->assertSame('100.0000', $result->ageWeight);
    }

    public function test_normal_average_three_factor_calculation(): void
    {
        $this->seedDependencies();
        $insuranceCompany = InsuranceCompany::query()->create([
            'code' => 'TEST',
            'name' => 'TEST INSURANCE',
            'claim_type' => InsuranceCompany::CLAIM_TYPE_AJK,
            'ckpn_weight' => '3.0000',
            'sla_description' => null,
            'is_active' => true,
        ]);
        $receivable = $this->receivable([
            'insurance_company_id' => $insuranceCompany->id,
            'receivable_formation_date' => '2026-01-01',
            'receivable_amount' => '10000.00',
        ]);

        $result = app(CkpnCalculationService::class)->calculate($this->input($receivable, '2026-06-30'));

        $this->assertSame('3.0000', $result->insuranceCompanyWeight);
        $this->assertSame('1.0000', $result->finalCkpnRate);
        $this->assertSame('100.00', $result->ckpnAmount);
    }

    public function test_reject_loss_over_365_days_returns_100_percent(): void
    {
        $this->seedDependencies();
        $rejectLoss = ClaimStatus::query()->where('code', 'reject_loss')->firstOrFail();
        $receivable = $this->receivable([
            'claim_status_id' => $rejectLoss->id,
            'receivable_formation_date' => '2026-01-01',
            'receivable_amount' => '10000.00',
        ]);

        $result = app(CkpnCalculationService::class)->calculate($this->input($receivable, '2027-01-02'));

        $this->assertSame('100.0000', $result->finalCkpnRate);
        $this->assertSame('10000.00', $result->ckpnAmount);
        $this->assertStringContainsString('overridden', $result->calculationExplanation);
    }

    public function test_reject_loss_365_days_or_less_uses_average_rule(): void
    {
        $this->seedDependencies();
        $rejectLoss = ClaimStatus::query()->where('code', 'reject_loss')->firstOrFail();
        $receivable = $this->receivable([
            'claim_status_id' => $rejectLoss->id,
            'receivable_formation_date' => '2026-01-01',
            'receivable_amount' => '10000.00',
        ]);

        $result = app(CkpnCalculationService::class)->calculate($this->input($receivable, '2026-06-30'));

        $this->assertSame('33.3333', $result->finalCkpnRate);
        $this->assertSame('3333.33', $result->ckpnAmount);
    }

    public function test_service_requires_active_valid_strategy_rule(): void
    {
        $this->seedDependencies();
        CkpnCalculationRule::query()->update(['is_active' => false]);
        $receivable = $this->receivable([
            'receivable_formation_date' => '2026-01-01',
            'receivable_amount' => '10000.00',
        ]);

        $this->expectException(ValidationException::class);

        app(CkpnCalculationService::class)->calculate($this->input($receivable, '2026-06-30'));
    }

    public function test_service_rejects_invalid_strategy_class(): void
    {
        $this->seedDependencies();
        CkpnCalculationRule::query()->where('is_active', true)->update([
            'strategy_class' => \stdClass::class,
        ]);
        $receivable = $this->receivable([
            'receivable_formation_date' => '2026-01-01',
            'receivable_amount' => '10000.00',
        ]);

        $this->expectException(ValidationException::class);

        app(CkpnCalculationService::class)->calculate($this->input($receivable, '2026-06-30'));
    }

    private function seedDependencies(): void
    {
        $this->seed([
            BranchOfficeSeeder::class,
            InsuranceCompanySeeder::class,
            ClaimStatusSeeder::class,
            CkpnAgeBucketSeeder::class,
            CkpnCalculationRuleSeeder::class,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function receivable(array $attributes = []): InsuranceReceivable
    {
        return InsuranceReceivable::factory()->create([
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
            ...$attributes,
        ]);
    }

    private function input(InsuranceReceivable $receivable, string $asOfDate): CkpnCalculationInput
    {
        $receivable = $receivable->refresh();
        $receivable->loadMissing(['branchOffice', 'insuranceCompany', 'claimStatus']);

        return new CkpnCalculationInput(
            candidate: new CkpnReceivableCandidate(
                receivableId: $receivable->id,
                originType: $receivable->origin_type ?? InsuranceReceivable::ORIGIN_TYPE_WORKFLOW,
                branchOfficeId: $receivable->branch_office_id,
                branchCode: $receivable->branch_code,
                branchName: $receivable->branchOffice->branch_name,
                cif: $receivable->cif_no,
                loanAccountNumber: $receivable->loan_account_number,
                customerName: $receivable->customer_name,
                insuranceCompanyId: $receivable->insurance_company_id,
                insuranceCompanyName: $receivable->insuranceCompany->name,
                insuranceCompanyWeight: $receivable->insuranceCompany->ckpn_weight,
                claimStatusId: $receivable->claim_status_id,
                claimStatusCode: $receivable->claimStatus->code,
                claimStatusName: $receivable->claimStatus->name,
                claimStatusWeight: $receivable->claimStatus->ckpn_weight,
                receivableFormationDate: $receivable->receivable_formation_date->toDateString(),
                receivableAmount: $receivable->receivable_amount,
            ),
            asOfDate: CarbonImmutable::parse($asOfDate),
        );
    }
}
