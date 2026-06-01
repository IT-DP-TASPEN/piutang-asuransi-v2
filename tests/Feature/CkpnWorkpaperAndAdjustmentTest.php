<?php

namespace Tests\Feature;

use App\Actions\Ckpn\GenerateMonthlyCkpnWorkpaperAction;
use App\Actions\Ckpn\RecalculateCkpnWorkpaperAction;
use App\Actions\CkpnAdjustment\ApproveCkpnAdjustmentAction;
use App\Actions\CkpnAdjustment\PrepareCkpnAdjustmentDataAction;
use App\Actions\CkpnAdjustment\RejectCkpnAdjustmentAction;
use App\Actions\CkpnAdjustment\SubmitCkpnAdjustmentAction;
use App\Actions\CkpnWorkpaper\ApproveCkpnWorkpaperAction;
use App\Actions\CkpnWorkpaper\SubmitCkpnWorkpaperAction;
use App\Actions\LegacyReceivable\RecordLegacyReceivablePaymentAction;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\BranchOffice;
use App\Models\CkpnAdjustment;
use App\Models\CkpnJournal;
use App\Models\CkpnWorkpaper;
use App\Models\InsuranceCompany;
use App\Models\InsuranceReceivable;
use App\Models\LegacyReceivable;
use App\Models\User;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\CkpnAgeBucketSeeder;
use Database\Seeders\CkpnCalculationRuleSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CkpnWorkpaperAndAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_workpaper_generation_creates_snapshot_items_and_totals(): void
    {
        $this->seedDependencies();
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $insuranceCompany = $this->insuranceCompany('TEST SNAPSHOT', '3.0000');
        $receivable = $this->receivable($branch, [
            'insurance_company_id' => $insuranceCompany->id,
            'customer_name' => 'Snapshot Customer',
            'receivable_formation_date' => '2026-01-01',
            'receivable_amount' => '10000.00',
        ]);
        $workpaper = CkpnWorkpaper::query()->create([
            'period' => '2026-06-30',
            'branch_office_id' => $branch->id,
            'created_by' => User::factory()->create()->id,
        ]);

        $workpaper = app(GenerateMonthlyCkpnWorkpaperAction::class)->handle($workpaper);
        $item = $workpaper->items()->sole();

        $this->assertSame(CkpnWorkpaper::STATUS_GENERATED, $workpaper->status);
        $this->assertSame('10000.00', $workpaper->total_receivable_amount);
        $this->assertSame('100.00', $workpaper->total_calculated_ckpn_amount);
        $this->assertSame('0.00', $workpaper->total_adjustment_delta);
        $this->assertSame('100.00', $workpaper->total_effective_ckpn_amount);
        $this->assertSame('100.00', $workpaper->total_ckpn_amount);
        $this->assertSame(InsuranceReceivable::class, $item->receivable_type);
        $this->assertSame($receivable->id, $item->receivable_id);
        $this->assertSame('Snapshot Customer', $item->customer_name);
        $this->assertSame('TEST SNAPSHOT', $item->insurance_company_name);
        $this->assertSame('1.0000', $item->calculated_ckpn_rate);
        $this->assertSame('100.00', $item->calculated_ckpn_amount);
        $this->assertSame('1.0000', $item->effective_ckpn_rate);
        $this->assertSame('100.00', $item->effective_ckpn_amount);
        $this->assertSame('TEST SNAPSHOT', $item->snapshot['insurance_company']['name']);
    }

    public function test_master_changes_do_not_mutate_existing_workpaper_item_snapshot(): void
    {
        $this->seedDependencies();
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $insuranceCompany = $this->insuranceCompany('IMMUTABLE', '3.0000');
        $receivable = $this->receivable($branch, [
            'insurance_company_id' => $insuranceCompany->id,
            'receivable_formation_date' => '2026-01-01',
            'receivable_amount' => '10000.00',
        ]);
        $workpaper = CkpnWorkpaper::query()->create([
            'period' => '2026-06-30',
            'branch_office_id' => $branch->id,
        ]);

        app(GenerateMonthlyCkpnWorkpaperAction::class)->handle($workpaper);
        $item = $workpaper->refresh()->items()->sole();

        $insuranceCompany->forceFill(['name' => 'CHANGED', 'ckpn_weight' => '100.0000'])->save();
        $receivable->claimStatus->forceFill(['name' => 'Changed Status', 'ckpn_weight' => '100.0000'])->save();

        $item = $item->refresh();
        $this->assertSame('IMMUTABLE', $item->insurance_company_name);
        $this->assertSame('3.0000', $item->insurance_company_weight);
        $this->assertSame('IMMUTABLE', $item->snapshot['insurance_company']['name']);
        $this->assertSame('1.0000', $item->calculated_ckpn_rate);
        $this->assertSame('1.0000', $item->effective_ckpn_rate);
    }

    public function test_branch_workpaper_only_includes_matching_branch_and_central_includes_all(): void
    {
        $this->seedDependencies();
        $branchOne = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $branchTwo = BranchOffice::query()->where('branch_code', '002')->firstOrFail();
        $this->receivable($branchOne, ['receivable_formation_date' => '2026-01-01', 'receivable_amount' => '10000.00']);
        $this->receivable($branchTwo, ['receivable_formation_date' => '2026-01-01', 'receivable_amount' => '20000.00']);

        $branchWorkpaper = CkpnWorkpaper::query()->create(['period' => '2026-06-30', 'branch_office_id' => $branchOne->id]);
        $centralWorkpaper = CkpnWorkpaper::query()->create(['period' => '2026-06-30']);

        app(GenerateMonthlyCkpnWorkpaperAction::class)->handle($branchWorkpaper);
        app(GenerateMonthlyCkpnWorkpaperAction::class)->handle($centralWorkpaper);

        $this->assertSame(1, $branchWorkpaper->refresh()->items()->count());
        $this->assertSame(2, $centralWorkpaper->refresh()->items()->count());
    }

    public function test_workpaper_generation_includes_legacy_receivables_with_period_end_outstanding(): void
    {
        $this->seedDependencies();
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $legacy = $this->legacyReceivable($branch, [
            'customer_name' => 'Legacy Customer',
            'original_receivable_amount' => '10000.00',
            'remaining_receivable_amount' => '10000.00',
            'receivable_formation_date' => '2026-01-01',
        ]);
        $maker = $this->userWithRole('accounting_maker', '000');

        app(RecordLegacyReceivablePaymentAction::class)->handle($legacy, [
            'amount' => '3000.00',
            'paid_at' => '2026-06-30',
        ], $maker);
        app(RecordLegacyReceivablePaymentAction::class)->handle($legacy->refresh(), [
            'amount' => '2000.00',
            'paid_at' => '2026-07-01',
        ], $maker);

        $workpaper = CkpnWorkpaper::query()->create(['period' => '2026-06-30', 'branch_office_id' => $branch->id]);
        $workpaper = app(GenerateMonthlyCkpnWorkpaperAction::class)->handle($workpaper);
        $item = $workpaper->items()->sole();

        $this->assertSame(LegacyReceivable::class, $item->receivable_type);
        $this->assertSame($legacy->id, $item->receivable_id);
        $this->assertSame('Legacy', $item->source_label);
        $this->assertSame('7000.00', $item->receivable_amount);
        $this->assertSame('7000.00', $workpaper->total_receivable_amount);
    }

    public function test_workpaper_generation_excludes_fully_paid_legacy_as_of_period(): void
    {
        $this->seedDependencies();
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $legacy = $this->legacyReceivable($branch, [
            'original_receivable_amount' => '10000.00',
            'remaining_receivable_amount' => '10000.00',
            'receivable_formation_date' => '2026-01-01',
        ]);
        $maker = $this->userWithRole('accounting_maker', '000');

        app(RecordLegacyReceivablePaymentAction::class)->handle($legacy, [
            'amount' => '10000.00',
            'paid_at' => '2026-06-30',
        ], $maker);

        $workpaper = CkpnWorkpaper::query()->create(['period' => '2026-06-30', 'branch_office_id' => $branch->id]);
        $workpaper = app(GenerateMonthlyCkpnWorkpaperAction::class)->handle($workpaper);

        $this->assertSame(0, $workpaper->items()->count());
        $this->assertSame('0.00', $workpaper->total_receivable_amount);
        $this->assertSame('0.00', $workpaper->total_effective_ckpn_amount);
    }

    public function test_recalculate_is_blocked_after_submit(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('business_maker', '000');
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $this->receivable($branch, ['receivable_formation_date' => '2026-01-01', 'receivable_amount' => '10000.00']);
        $workpaper = CkpnWorkpaper::query()->create(['period' => '2026-06-30', 'branch_office_id' => $branch->id]);
        $workpaper = app(GenerateMonthlyCkpnWorkpaperAction::class)->handle($workpaper);
        $workpaper = app(SubmitCkpnWorkpaperAction::class)->handle($workpaper, $maker);

        $this->expectException(ValidationException::class);

        app(RecalculateCkpnWorkpaperAction::class)->handle($workpaper);
    }

    public function test_workpaper_submit_and_approve_finalizes_status(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('business_maker', '000');
        $approver = $this->userWithRole('accounting_approver', '000');
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $workpaper = CkpnWorkpaper::query()->create([
            'period' => '2026-06-30',
            'branch_office_id' => $branch->id,
            'status' => CkpnWorkpaper::STATUS_GENERATED,
        ]);

        $workpaper = app(SubmitCkpnWorkpaperAction::class)->handle($workpaper, $maker);
        $this->assertSame(CkpnWorkpaper::STATUS_SUBMITTED, $workpaper->status);
        $this->assertSame(ApprovalRequest::WORKFLOW_MONTHLY_CKPN_WORKPAPER, ApprovalRequest::query()->sole()->workflow_code);
        $this->assertSame('accounting_approver', ApprovalStep::query()->sole()->role_name);

        $workpaper = app(ApproveCkpnWorkpaperAction::class)->handle($workpaper, $approver);

        $this->assertSame(CkpnWorkpaper::STATUS_APPROVED, $workpaper->status);
        $this->assertSame($approver->id, $workpaper->approved_by);
        $this->assertNotNull($workpaper->approved_at);
    }

    public function test_adjustment_requires_reason_and_approval_preserves_item_values(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('business_maker', '000');
        $approver = $this->userWithRole('accounting_approver', '000');
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $this->receivable($branch, ['receivable_formation_date' => '2026-01-01', 'receivable_amount' => '10000.00']);
        $workpaper = CkpnWorkpaper::query()->create(['period' => '2026-06-30', 'branch_office_id' => $branch->id]);
        $workpaper = app(GenerateMonthlyCkpnWorkpaperAction::class)->handle($workpaper);
        $item = $workpaper->items()->sole();

        $this->expectException(ValidationException::class);
        app(PrepareCkpnAdjustmentDataAction::class)->handle([
            'ckpn_workpaper_item_id' => $item->id,
            'requested_adjusted_ckpn_amount' => '200.00',
        ], $maker);
    }

    public function test_pending_rejected_and_approved_adjustments_update_only_effective_values(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('business_maker', '000');
        $approver = $this->userWithRole('accounting_approver', '000');
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $this->receivable($branch, ['receivable_formation_date' => '2026-01-01', 'receivable_amount' => '10000.00']);
        $workpaper = CkpnWorkpaper::query()->create(['period' => '2026-06-30', 'branch_office_id' => $branch->id]);
        $workpaper = app(GenerateMonthlyCkpnWorkpaperAction::class)->handle($workpaper);
        $item = $workpaper->items()->sole();

        $payload = app(PrepareCkpnAdjustmentDataAction::class)->handle([
            'ckpn_workpaper_item_id' => $item->id,
            'requested_adjusted_ckpn_amount' => '200.00',
            'reason' => 'Management adjustment',
        ], $maker);
        $adjustment = CkpnAdjustment::query()->create($payload);
        $adjustment = app(SubmitCkpnAdjustmentAction::class)->handle($adjustment, $maker);

        $this->assertSame(CkpnAdjustment::STATUS_SUBMITTED, $adjustment->status);
        $this->assertSame(ApprovalRequest::WORKFLOW_CKPN_ADJUSTMENT, ApprovalRequest::query()->sole()->workflow_code);
        $this->assertSame('0.00', $item->refresh()->effective_ckpn_amount);

        $adjustment = app(ApproveCkpnAdjustmentAction::class)->handle($adjustment, $approver);
        $item = $item->refresh();
        $workpaper = $workpaper->refresh();

        $this->assertSame(CkpnAdjustment::STATUS_APPROVED, $adjustment->status);
        $this->assertSame('0.0000', $item->calculated_ckpn_rate);
        $this->assertSame('0.00', $item->calculated_ckpn_amount);
        $this->assertSame('2.0000', $item->effective_ckpn_rate);
        $this->assertSame('200.00', $item->effective_ckpn_amount);
        $this->assertSame('2.0000', $adjustment->approved_adjusted_ckpn_rate);
        $this->assertSame('200.00', $adjustment->approved_adjusted_ckpn_amount);
        $this->assertSame('0.00', $workpaper->total_calculated_ckpn_amount);
        $this->assertSame('200.00', $workpaper->total_adjustment_delta);
        $this->assertSame('200.00', $workpaper->total_effective_ckpn_amount);
        $this->assertSame('200.00', $workpaper->total_ckpn_amount);

        $secondPayload = app(PrepareCkpnAdjustmentDataAction::class)->handle([
            'ckpn_workpaper_item_id' => $item->id,
            'requested_adjusted_ckpn_amount' => '300.00',
            'reason' => 'Superseding adjustment',
        ], $maker);
        $secondAdjustment = CkpnAdjustment::query()->create($secondPayload);
        $secondAdjustment = app(SubmitCkpnAdjustmentAction::class)->handle($secondAdjustment, $maker);
        $secondAdjustment = app(ApproveCkpnAdjustmentAction::class)->handle($secondAdjustment, $approver);

        $this->assertSame(CkpnAdjustment::STATUS_APPROVED, $secondAdjustment->status);
        $this->assertSame('300.00', $item->refresh()->effective_ckpn_amount);
        $this->assertSame('300.00', $workpaper->refresh()->total_effective_ckpn_amount);
    }

    public function test_rejected_adjustment_does_not_change_effective_values(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('business_maker', '000');
        $approver = $this->userWithRole('accounting_approver', '000');
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $this->receivable($branch, ['receivable_formation_date' => '2026-01-01', 'receivable_amount' => '10000.00']);
        $workpaper = CkpnWorkpaper::query()->create(['period' => '2026-06-30', 'branch_office_id' => $branch->id]);
        $workpaper = app(GenerateMonthlyCkpnWorkpaperAction::class)->handle($workpaper);
        $item = $workpaper->items()->sole();

        $payload = app(PrepareCkpnAdjustmentDataAction::class)->handle([
            'ckpn_workpaper_item_id' => $item->id,
            'requested_adjusted_ckpn_amount' => '200.00',
            'reason' => 'Rejected adjustment',
        ], $maker);
        $adjustment = CkpnAdjustment::query()->create($payload);
        $adjustment = app(SubmitCkpnAdjustmentAction::class)->handle($adjustment, $maker);
        app(RejectCkpnAdjustmentAction::class)->handle($adjustment, $approver, 'No');

        $this->assertSame('0.00', $item->refresh()->effective_ckpn_amount);
        $this->assertSame('0.00', $workpaper->refresh()->total_effective_ckpn_amount);
    }

    public function test_adjustment_approval_is_blocked_after_financial_output_exists(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('business_maker', '000');
        $approver = $this->userWithRole('accounting_approver', '000');
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $this->receivable($branch, ['receivable_formation_date' => '2026-01-01', 'receivable_amount' => '10000.00']);
        $workpaper = CkpnWorkpaper::query()->create(['period' => '2026-06-30', 'branch_office_id' => $branch->id]);
        $workpaper = app(GenerateMonthlyCkpnWorkpaperAction::class)->handle($workpaper);
        $item = $workpaper->items()->sole();

        $payload = app(PrepareCkpnAdjustmentDataAction::class)->handle([
            'ckpn_workpaper_item_id' => $item->id,
            'requested_adjusted_ckpn_amount' => '200.00',
            'reason' => 'Late adjustment',
        ], $maker);
        $adjustment = CkpnAdjustment::query()->create($payload);
        $adjustment = app(SubmitCkpnAdjustmentAction::class)->handle($adjustment, $maker);

        CkpnJournal::query()->create([
            'ckpn_workpaper_id' => $workpaper->id,
            'branch_office_id' => $branch->id,
            'journal_date' => '2026-06-30',
            'total_amount' => $workpaper->total_effective_ckpn_amount,
            'status' => CkpnJournal::STATUS_DRAFT,
        ]);

        $this->expectException(ValidationException::class);

        app(ApproveCkpnAdjustmentAction::class)->handle($adjustment, $approver);
    }

    private function seedDependencies(): void
    {
        $this->seed([
            BranchOfficeSeeder::class,
            InsuranceCompanySeeder::class,
            ClaimStatusSeeder::class,
            CkpnAgeBucketSeeder::class,
            CkpnCalculationRuleSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    private function insuranceCompany(string $name, string $weight): InsuranceCompany
    {
        return InsuranceCompany::query()->create([
            'code' => null,
            'name' => $name,
            'ckpn_weight' => $weight,
            'sla_description' => null,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function receivable(BranchOffice $branch, array $attributes = []): InsuranceReceivable
    {
        return InsuranceReceivable::factory()->create([
            'branch_office_id' => $branch->id,
            'branch_code' => $branch->branch_code,
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function legacyReceivable(BranchOffice $branch, array $attributes = []): LegacyReceivable
    {
        return LegacyReceivable::factory()->create([
            'branch_office_id' => $branch->id,
            'date_of_death' => '2026-01-01',
            ...$attributes,
        ]);
    }

    private function userWithRole(string $role, string $branchCode): User
    {
        $branchOffice = BranchOffice::query()->where('branch_code', $branchCode)->firstOrFail();
        $user = User::factory()->create(['branch_office_id' => $branchOffice->id]);
        $user->assignRole($role);

        return $user;
    }
}
