<?php

namespace Tests\Feature;

use App\Actions\Ckpn\CreateAllBranchCkpnWorkpapersAction;
use App\Actions\Ckpn\CreateCkpnWorkpaperAction;
use App\Actions\Ckpn\GenerateMonthlyCkpnWorkpaperAction;
use App\Actions\Ckpn\RecalculateCkpnWorkpaperAction;
use App\Actions\CkpnAdjustment\ApproveCkpnAdjustmentAction;
use App\Actions\CkpnAdjustment\CancelCkpnAdjustmentAction;
use App\Actions\CkpnAdjustment\PrepareCkpnAdjustmentDataAction;
use App\Actions\CkpnAdjustment\RejectCkpnAdjustmentAction;
use App\Actions\CkpnAdjustment\SubmitCkpnAdjustmentAction;
use App\Actions\CkpnJournal\CreateCkpnJournalFromWorkpaperAction;
use App\Actions\CkpnWorkpaper\ApproveCkpnWorkpaperAction;
use App\Actions\CkpnWorkpaper\SubmitCkpnWorkpaperAction;
use App\Actions\InsuranceReceivable\CancelInsuranceReceivableAction;
use App\Actions\ReceivablePayment\RecordReceivablePaymentAction;
use App\Jobs\GenerateCkpnWorkpaperJob;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\BranchOffice;
use App\Models\CkpnAdjustment;
use App\Models\CkpnJournal;
use App\Models\CkpnWorkpaper;
use App\Models\ClaimStatus;
use App\Models\ClaimStatusChangeRequest;
use App\Models\InsuranceCompany;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\Ckpn\CkpnWorkpaperReadinessValidator;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\CkpnAgeBucketSeeder;
use Database\Seeders\CkpnCalculationRuleSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CkpnWorkpaperAndAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_workpaper_period_is_exact_cutoff_date(): void
    {
        $this->assertSame('2026-06-15 00:00:00', CkpnWorkpaper::normalizePeriod('2026-06-15')->toDateTimeString());

        $workpaper = CkpnWorkpaper::query()->create(['period' => '2026-06-15 13:45:00']);

        $this->assertSame('2026-06-15', $workpaper->period->toDateString());
        $this->assertSame('2026-06-15 23:59:59', $workpaper->periodEnd()->toDateTimeString());
        $this->assertNotSame('2026-06-01', $workpaper->period->toDateString());
        $this->assertNotSame('2026-06-30 23:59:59', $workpaper->periodEnd()->toDateTimeString());
    }

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
        $this->assertSame(InsuranceReceivable::ORIGIN_TYPE_WORKFLOW, $item->origin_type);
        $this->assertSame($receivable->id, $item->insurance_receivable_id);
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

    public function test_workpaper_generation_uses_exact_cutoff_for_eligibility_and_aging(): void
    {
        $this->seedDependencies();
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $older = $this->receivable($branch, [
            'receivable_formation_date' => '2026-05-20',
            'receivable_amount' => '10000.00',
        ]);
        $onCutoff = $this->receivable($branch, [
            'receivable_formation_date' => '2026-06-15',
            'receivable_amount' => '20000.00',
        ]);
        $afterCutoff = $this->receivable($branch, [
            'receivable_formation_date' => '2026-06-20',
            'receivable_amount' => '30000.00',
        ]);
        $workpaper = CkpnWorkpaper::query()->create([
            'period' => '2026-06-15',
            'branch_office_id' => $branch->id,
        ]);

        $workpaper = app(GenerateMonthlyCkpnWorkpaperAction::class)->handle($workpaper);
        $items = $workpaper->items()->orderBy('insurance_receivable_id')->get();

        $this->assertSame([$older->id, $onCutoff->id], $items->pluck('insurance_receivable_id')->all());
        $this->assertFalse($items->contains('insurance_receivable_id', $afterCutoff->id));
        $this->assertSame(26, $items->firstWhere('insurance_receivable_id', $older->id)->age_days);
        $this->assertSame(0, $items->firstWhere('insurance_receivable_id', $onCutoff->id)->age_days);
    }

    public function test_workpaper_generation_uses_current_legacy_remaining_amount_not_payment_period_cutoff(): void
    {
        $this->seedDependencies();
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $legacy = $this->legacyReceivable($branch, [
            'customer_name' => 'Legacy Customer',
            'receivable_amount' => '10000.00',
            'remaining_receivable_amount' => '10000.00',
            'receivable_formation_date' => '2026-01-01',
        ]);
        $maker = $this->userWithRole('accounting_maker', '000');

        app(RecordReceivablePaymentAction::class)->handle($legacy, [
            'amount' => '3000.00',
            'paid_at' => '2026-06-30',
        ], $maker);
        app(RecordReceivablePaymentAction::class)->handle($legacy->refresh(), [
            'amount' => '2000.00',
            'paid_at' => '2026-07-01',
        ], $maker);

        $workpaper = CkpnWorkpaper::query()->create(['period' => '2026-06-30', 'branch_office_id' => $branch->id]);
        $workpaper = app(GenerateMonthlyCkpnWorkpaperAction::class)->handle($workpaper);
        $item = $workpaper->items()->sole();

        $this->assertSame(InsuranceReceivable::ORIGIN_TYPE_LEGACY, $item->origin_type);
        $this->assertSame($legacy->id, $item->insurance_receivable_id);
        $this->assertSame('Legacy', $item->source_label);
        $this->assertSame('5000.00', $item->receivable_amount);
        $this->assertSame('5000.00', $workpaper->total_receivable_amount);
    }

    public function test_workpaper_generation_excludes_fully_paid_legacy_by_current_remaining_amount(): void
    {
        $this->seedDependencies();
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $legacy = $this->legacyReceivable($branch, [
            'receivable_amount' => '10000.00',
            'remaining_receivable_amount' => '10000.00',
            'receivable_formation_date' => '2026-01-01',
        ]);
        $maker = $this->userWithRole('accounting_maker', '000');

        app(RecordReceivablePaymentAction::class)->handle($legacy, [
            'amount' => '10000.00',
            'paid_at' => '2026-06-30',
        ], $maker);

        $workpaper = CkpnWorkpaper::query()->create(['period' => '2026-06-30', 'branch_office_id' => $branch->id]);
        $workpaper = app(GenerateMonthlyCkpnWorkpaperAction::class)->handle($workpaper);

        $this->assertSame(0, $workpaper->items()->count());
        $this->assertSame('0.00', $workpaper->total_receivable_amount);
        $this->assertSame('0.00', $workpaper->total_effective_ckpn_amount);
    }

    public function test_workpaper_generation_uses_current_insurance_remaining_amount(): void
    {
        $this->seedDependencies();
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $receivable = $this->receivable($branch, [
            'receivable_formation_date' => '2026-01-01',
            'receivable_amount' => '10000.00',
            'remaining_receivable_amount' => '10000.00',
        ]);
        $maker = $this->userWithRole('accounting_maker', '000');

        app(RecordReceivablePaymentAction::class)->handle($receivable, [
            'amount' => '3000.00',
            'paid_at' => '2026-07-01',
        ], $maker);

        $workpaper = CkpnWorkpaper::query()->create(['period' => '2026-06-30', 'branch_office_id' => $branch->id]);
        $workpaper = app(GenerateMonthlyCkpnWorkpaperAction::class)->handle($workpaper);
        $item = $workpaper->items()->sole();

        $this->assertSame(InsuranceReceivable::ORIGIN_TYPE_WORKFLOW, $item->origin_type);
        $this->assertSame('7000.00', $item->receivable_amount);
        $this->assertSame('7000.00', $workpaper->total_receivable_amount);
    }

    public function test_payment_after_locked_workpaper_does_not_mutate_existing_item_and_affects_next_generation(): void
    {
        $this->seedDependencies();
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $receivable = $this->receivable($branch, [
            'receivable_formation_date' => '2026-01-01',
            'receivable_amount' => '10000.00',
            'remaining_receivable_amount' => '10000.00',
        ]);
        $maker = $this->userWithRole('accounting_maker', '000');

        $lockedWorkpaper = CkpnWorkpaper::query()->create(['period' => '2026-06-30', 'branch_office_id' => $branch->id]);
        $lockedWorkpaper = app(GenerateMonthlyCkpnWorkpaperAction::class)->handle($lockedWorkpaper);
        $lockedItem = $lockedWorkpaper->items()->sole();
        $lockedWorkpaper->forceFill(['status' => CkpnWorkpaper::STATUS_LOCKED])->save();

        app(RecordReceivablePaymentAction::class)->handle($receivable, [
            'amount' => '3000.00',
            'paid_at' => '2026-07-01',
        ], $maker);

        $this->assertSame('10000.00', $lockedItem->refresh()->receivable_amount);
        $this->assertSame('10000.00', $lockedWorkpaper->refresh()->total_receivable_amount);

        $nextWorkpaper = CkpnWorkpaper::query()->create(['period' => '2026-07-31', 'branch_office_id' => $branch->id]);
        $nextWorkpaper = app(GenerateMonthlyCkpnWorkpaperAction::class)->handle($nextWorkpaper);

        $this->assertSame('7000.00', $nextWorkpaper->items()->sole()->receivable_amount);
        $this->assertSame('7000.00', $nextWorkpaper->total_receivable_amount);
    }

    public function test_recalculate_is_blocked_after_submit(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('accounting_maker', '000');
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
        $maker = $this->userWithRole('accounting_maker', '000');
        $approver = $this->userWithRole('accounting_approver', '000');
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $this->receivable($branch, ['receivable_formation_date' => '2026-01-01', 'receivable_amount' => '10000.00']);
        $workpaper = CkpnWorkpaper::query()->create([
            'period' => '2026-06-30',
            'branch_office_id' => $branch->id,
        ]);
        $workpaper = app(GenerateMonthlyCkpnWorkpaperAction::class)->handle($workpaper);

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
        $maker = $this->userWithRole('accounting_maker', '000');
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
        $maker = $this->userWithRole('accounting_maker', '000');
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
        $maker = $this->userWithRole('accounting_maker', '000');
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
        $maker = $this->userWithRole('accounting_maker', '000');
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

    public function test_create_workpaper_preserves_cutoff_date_allows_same_month_and_prevents_duplicate_branch_cutoff(): void
    {
        $this->seedDependencies();
        Queue::fake();
        $maker = $this->userWithRole('accounting_maker', '000');
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();

        $workpaper = app(CreateCkpnWorkpaperAction::class)->handle([
            'period' => '2026-04-15',
            'branch_office_id' => $branch->id,
        ], $maker);

        $this->assertSame('2026-04-15', $workpaper->period->toDateString());
        $this->assertSame("branch:{$branch->id}", $workpaper->branch_scope_key);
        $this->assertSame(CkpnWorkpaper::STATUS_GENERATION_QUEUED, $workpaper->status);
        Queue::assertPushed(GenerateCkpnWorkpaperJob::class, fn (GenerateCkpnWorkpaperJob $job): bool => $job->ckpnWorkpaperId === $workpaper->id);

        $secondWorkpaper = app(CreateCkpnWorkpaperAction::class)->handle([
            'period' => '2026-04-30',
            'branch_office_id' => $branch->id,
        ], $maker);

        $this->assertSame('2026-04-30', $secondWorkpaper->period->toDateString());

        $this->expectException(ValidationException::class);

        app(CreateCkpnWorkpaperAction::class)->handle([
            'period' => '2026-04-15',
            'branch_office_id' => $branch->id,
        ], $maker);
    }

    public function test_create_all_branch_workpapers_queues_active_operational_branches(): void
    {
        $this->seedDependencies();
        Queue::fake();
        $maker = $this->userWithRole('accounting_maker', '000');
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        BranchOffice::query()->where('branch_code', '003')->update(['is_active' => false]);
        CkpnWorkpaper::query()->create([
            'period' => '2026-06-30',
            'branch_office_id' => $branch->id,
            'status' => CkpnWorkpaper::STATUS_CANCELLED,
        ]);
        InsuranceReceivable::factory()->create([
            'branch_office_id' => $branch->id,
            'branch_code' => $branch->branch_code,
            'date_of_death' => '2026-06-16',
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
        ]);

        $workpapers = app(CreateAllBranchCkpnWorkpapersAction::class)->handle([
            'period' => '2026-06-15',
        ], $maker);

        $expectedBranches = BranchOffice::query()
            ->where('is_active', true)
            ->where('branch_code', '!=', '000')
            ->orderBy('branch_code')
            ->orderBy('id')
            ->get();
        $created = CkpnWorkpaper::query()
            ->with('branchOffice')
            ->whereDate('period', '2026-06-15')
            ->orderBy('id')
            ->get();

        $this->assertCount($expectedBranches->count(), $workpapers);
        $this->assertSame($expectedBranches->pluck('branch_code')->all(), $created->pluck('branchOffice.branch_code')->all());
        $this->assertNotContains('000', $created->pluck('branchOffice.branch_code')->all());
        $created->each(function (CkpnWorkpaper $workpaper) use ($maker): void {
            $this->assertSame('2026-06-15', $workpaper->period->toDateString());
            $this->assertSame("branch:{$workpaper->branch_office_id}", $workpaper->branch_scope_key);
            $this->assertSame(CkpnWorkpaper::STATUS_GENERATION_QUEUED, $workpaper->status);
            $this->assertSame($maker->id, $workpaper->created_by);
        });
        Queue::assertPushed(GenerateCkpnWorkpaperJob::class, $expectedBranches->count());
    }

    public function test_create_all_branch_workpapers_blocks_duplicate_cutoff_for_any_status(): void
    {
        $this->seedDependencies();
        Queue::fake();
        $maker = $this->userWithRole('accounting_maker', '000');
        $branchOne = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $branchTwo = BranchOffice::query()->where('branch_code', '002')->firstOrFail();
        CkpnWorkpaper::query()->create([
            'period' => '2026-07-31',
            'branch_office_id' => $branchOne->id,
            'status' => CkpnWorkpaper::STATUS_CANCELLED,
        ]);
        CkpnWorkpaper::query()->create([
            'period' => '2026-07-15',
            'branch_office_id' => $branchTwo->id,
            'status' => CkpnWorkpaper::STATUS_REJECTED,
        ]);

        try {
            app(CreateAllBranchCkpnWorkpapersAction::class)->handle(['period' => '2026-07-15'], $maker);

            $this->fail('Duplicate branch cutoff should block bulk creation.');
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            $this->assertStringContainsString('already have workpapers for cutoff date 2026-07-15', $message);
            $this->assertStringContainsString($branchTwo->branch_name, $message);
        }

        $this->assertSame(2, CkpnWorkpaper::query()->count());
        Queue::assertNotPushed(GenerateCkpnWorkpaperJob::class);
    }

    public function test_create_all_branch_workpapers_blocks_pending_receivable_on_cutoff(): void
    {
        $this->seedDependencies();
        Queue::fake();
        $maker = $this->userWithRole('accounting_maker', '000');
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        InsuranceReceivable::factory()->create([
            'branch_office_id' => $branch->id,
            'branch_code' => $branch->branch_code,
            'date_of_death' => '2026-06-15',
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
        ]);

        try {
            app(CreateAllBranchCkpnWorkpapersAction::class)->handle(['period' => '2026-06-15'], $maker);

            $this->fail('Pending receivable should block bulk workpaper creation.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Insurance Receivables are still pending', collect($exception->errors())->flatten()->first());
        }

        $this->assertSame(0, CkpnWorkpaper::query()->count());
        Queue::assertNotPushed(GenerateCkpnWorkpaperJob::class);
    }

    public function test_create_all_branch_workpapers_requires_active_operational_branches(): void
    {
        $this->seedDependencies();
        Queue::fake();
        $maker = $this->userWithRole('accounting_maker', '000');
        BranchOffice::query()->where('branch_code', '!=', '000')->update(['is_active' => false]);

        try {
            app(CreateAllBranchCkpnWorkpapersAction::class)->handle(['period' => '2026-08-15'], $maker);

            $this->fail('No target branches should block bulk workpaper creation.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'No active branches are available for CKPN Workpaper creation.',
                collect($exception->errors())->flatten()->first(),
            );
        }

        $this->assertSame(0, CkpnWorkpaper::query()->count());
        Queue::assertNotPushed(GenerateCkpnWorkpaperJob::class);
    }

    public function test_create_all_branch_workpapers_uses_overlap_lock(): void
    {
        $this->seedDependencies();
        Queue::fake();
        $maker = $this->userWithRole('accounting_maker', '000');
        $lock = Cache::lock('ckpn-workpapers:bulk-create-all-branches:2026-08-15', 120);
        $this->assertTrue($lock->get());

        try {
            try {
                app(CreateAllBranchCkpnWorkpapersAction::class)->handle(['period' => '2026-08-15'], $maker);

                $this->fail('Overlap lock should block bulk workpaper creation.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('already being created', collect($exception->errors())->flatten()->first());
            }
        } finally {
            $lock->release();
        }

        $this->assertSame(0, CkpnWorkpaper::query()->count());
        Queue::assertNotPushed(GenerateCkpnWorkpaperJob::class);
    }

    public function test_create_all_branch_workpapers_rolls_back_unique_race(): void
    {
        $this->seedDependencies();
        Queue::fake();
        $maker = $this->userWithRole('accounting_maker', '000');
        $branchOne = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $branchTwo = BranchOffice::query()->where('branch_code', '002')->firstOrFail();
        CkpnWorkpaper::query()->create([
            'period' => '2026-09-15',
            'branch_office_id' => $branchTwo->id,
            'status' => CkpnWorkpaper::STATUS_CANCELLED,
        ]);
        $this->app->instance(CkpnWorkpaperReadinessValidator::class, new class extends CkpnWorkpaperReadinessValidator
        {
            public function assertNoDuplicateWorkpapersForBranches(Carbon|string $period, Collection $branches): void {}
        });

        try {
            app(CreateAllBranchCkpnWorkpapersAction::class)->handle(['period' => '2026-09-15'], $maker);

            $this->fail('Unique race should be converted to a validation error.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('already have workpapers for cutoff date 2026-09-15', collect($exception->errors())->flatten()->first());
        }

        $this->assertSame(1, CkpnWorkpaper::query()->count());
        $this->assertDatabaseMissing('ckpn_workpapers', [
            'period' => '2026-09-15',
            'branch_office_id' => $branchOne->id,
        ]);
        Queue::assertNotPushed(GenerateCkpnWorkpaperJob::class);
    }

    public function test_create_all_branch_workpapers_uses_create_authorization(): void
    {
        $this->seedDependencies();
        Queue::fake();
        $businessMaker = $this->userWithRole('business_maker', '000');

        try {
            app(CreateAllBranchCkpnWorkpapersAction::class)->handle(['period' => '2026-10-15'], $businessMaker);

            $this->fail('Business maker should not create CKPN workpapers in bulk.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Only accounting maker can create CKPN workpapers.', collect($exception->errors())->flatten()->first());
        }

        $this->assertSame(0, CkpnWorkpaper::query()->count());
        Queue::assertNotPushed(GenerateCkpnWorkpaperJob::class);
    }

    public function test_ckpn_workpaper_creation_is_accounting_owned_and_business_read_only(): void
    {
        $this->seedDependencies();
        Queue::fake();
        $accountingMaker = $this->userWithRole('accounting_maker', '000');
        $businessMaker = $this->userWithRole('business_maker', '000');
        $businessApprover = $this->userWithRole('business_approver', '000');
        $accountingApprover = $this->userWithRole('accounting_approver', '000');
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $workpaper = CkpnWorkpaper::query()->create([
            'period' => '2026-05-01',
            'branch_office_id' => $branch->id,
            'status' => CkpnWorkpaper::STATUS_SUBMITTED,
        ]);

        $this->assertTrue($accountingMaker->can('create', CkpnWorkpaper::class));
        $this->assertFalse($businessMaker->can('create', CkpnWorkpaper::class));
        $this->assertFalse($businessMaker->can('update', $workpaper));
        $this->assertFalse($businessApprover->can('approve', $workpaper));
        $this->assertTrue($accountingApprover->can('approve', $workpaper));

        try {
            app(CreateCkpnWorkpaperAction::class)->handle([
                'period' => '2026-04-01',
                'branch_office_id' => $branch->id,
            ], $businessMaker);

            $this->fail('Business maker should not create CKPN workpapers.');
        } catch (ValidationException) {
        }

        $created = app(CreateCkpnWorkpaperAction::class)->handle([
            'period' => '2026-04-01',
            'branch_office_id' => $branch->id,
        ], $accountingMaker);

        $this->assertSame(CkpnWorkpaper::STATUS_GENERATION_QUEUED, $created->status);
        Queue::assertPushed(GenerateCkpnWorkpaperJob::class, fn (GenerateCkpnWorkpaperJob $job): bool => $job->ckpnWorkpaperId === $created->id);
    }

    public function test_create_workpaper_prevents_duplicate_central_scope(): void
    {
        $this->seedDependencies();
        Queue::fake();
        $maker = $this->userWithRole('accounting_maker', '000');

        app(CreateCkpnWorkpaperAction::class)->handle(['period' => '2026-04-15'], $maker);
        $workpaper = app(CreateCkpnWorkpaperAction::class)->handle(['period' => '2026-04-30'], $maker);

        $this->assertSame('2026-04-30', $workpaper->period->toDateString());

        $this->expectException(ValidationException::class);

        app(CreateCkpnWorkpaperAction::class)->handle(['period' => '2026-04-15'], $maker);
    }

    public function test_pending_receivable_cutoff_uses_selected_day_end_and_fallback_date(): void
    {
        $this->seedDependencies();
        Queue::fake();
        $maker = $this->userWithRole('accounting_maker', '000');
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();

        InsuranceReceivable::factory()->create([
            'branch_office_id' => $branch->id,
            'branch_code' => $branch->branch_code,
            'date_of_death' => '2026-04-15',
            'receivable_formation_date' => null,
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED,
        ]);

        $this->expectException(ValidationException::class);

        app(CreateCkpnWorkpaperAction::class)->handle([
            'period' => '2026-04-15',
            'branch_office_id' => $branch->id,
        ], $maker);
    }

    public function test_pending_receivable_after_cutoff_does_not_block_workpaper(): void
    {
        $this->seedDependencies();
        Queue::fake();
        $maker = $this->userWithRole('accounting_maker', '000');
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();

        InsuranceReceivable::factory()->create([
            'branch_office_id' => $branch->id,
            'branch_code' => $branch->branch_code,
            'date_of_death' => '2026-04-16',
            'receivable_formation_date' => null,
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED,
        ]);

        $workpaper = app(CreateCkpnWorkpaperAction::class)->handle([
            'period' => '2026-04-15',
            'branch_office_id' => $branch->id,
        ], $maker);

        $this->assertSame(CkpnWorkpaper::STATUS_GENERATION_QUEUED, $workpaper->status);
    }

    public function test_manual_failed_receivable_resolution_unblocks_workpaper_readiness(): void
    {
        $this->seedDependencies();
        Queue::fake();
        $maker = $this->userWithRole('branch_maker', '001');
        $accountingMaker = $this->userWithRole('accounting_maker', '000');
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $receivable = InsuranceReceivable::factory()->create([
            'branch_office_id' => $branch->id,
            'branch_code' => $branch->branch_code,
            'date_of_death' => '2026-04-15',
            'receivable_formation_date' => null,
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
        ]);
        $receivable->forceFill([
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_INQUIRY_FAILED,
        ])->saveQuietly();

        try {
            app(CreateCkpnWorkpaperAction::class)->handle([
                'period' => '2026-04-15',
                'branch_office_id' => $branch->id,
            ], $accountingMaker);

            $this->fail('Pending failed receivable should block workpaper creation.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Insurance Receivables are still pending', collect($exception->errors())->flatten()->first());
        }

        $receivable = app(CancelInsuranceReceivableAction::class)->handle($receivable, $maker, 'Invalid inquiry data.');

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_CANCELLED, $receivable->workflow_status);
        $this->assertTrue($receivable->stageLogs()->where('event', 'technical_inquiry_failure_cancelled')->exists());

        $workpaper = app(CreateCkpnWorkpaperAction::class)->handle([
            'period' => '2026-04-15',
            'branch_office_id' => $branch->id,
        ], $accountingMaker);

        $this->assertSame(CkpnWorkpaper::STATUS_GENERATION_QUEUED, $workpaper->status);
    }

    public function test_pending_claim_status_updates_block_workpaper_only_for_cutoff_scope(): void
    {
        $this->seedDependencies();
        Queue::fake();
        $businessMaker = $this->userWithRole('business_maker', '000');
        $accountingMaker = $this->userWithRole('accounting_maker', '000');
        $branchOne = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $branchTwo = BranchOffice::query()->where('branch_code', '002')->firstOrFail();
        $fromStatus = ClaimStatus::query()->where('code', ClaimStatus::DEFAULT_CODE)->firstOrFail();
        $toStatus = ClaimStatus::query()->where('code', 'approved')->firstOrFail();
        $receivable = InsuranceReceivable::factory()->create([
            'branch_office_id' => $branchOne->id,
            'branch_code' => $branchOne->branch_code,
            'claim_status_id' => $fromStatus->id,
            'receivable_formation_date' => '2026-04-15',
            'receivable_amount' => '1000.00',
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_RESOLVED,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_RESOLVED,
        ]);
        $request = $receivable->claimStatusChangeRequests()->create([
            'from_claim_status_id' => $fromStatus->id,
            'to_claim_status_id' => $toStatus->id,
            'requested_by' => $businessMaker->id,
            'status' => ClaimStatusChangeRequest::STATUS_RETURNED,
        ]);

        try {
            app(CreateCkpnWorkpaperAction::class)->handle([
                'period' => '2026-04-15',
                'branch_office_id' => $branchOne->id,
            ], $accountingMaker);

            $this->fail('Pending claim status request should block scoped CKPN workpaper.');
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            $this->assertStringContainsString('1 claim status update requests', $message);
            $this->assertStringContainsString("#{$request->id} returned", $message);
        }

        $otherBranchWorkpaper = app(CreateCkpnWorkpaperAction::class)->handle([
            'period' => '2026-04-15',
            'branch_office_id' => $branchTwo->id,
        ], $accountingMaker);

        $this->assertSame(CkpnWorkpaper::STATUS_GENERATION_QUEUED, $otherBranchWorkpaper->status);

        $request->forceFill(['status' => ClaimStatusChangeRequest::STATUS_CANCELLED])->save();

        $workpaper = app(CreateCkpnWorkpaperAction::class)->handle([
            'period' => '2026-04-15',
            'branch_office_id' => $branchOne->id,
        ], $accountingMaker);

        $this->assertSame(CkpnWorkpaper::STATUS_GENERATION_QUEUED, $workpaper->status);
    }

    public function test_generate_job_is_idempotent_and_skips_stale_unsafe_status(): void
    {
        $this->seedDependencies();
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $this->receivable($branch, ['receivable_formation_date' => '2026-04-30', 'receivable_amount' => '10000.00']);
        $workpaper = CkpnWorkpaper::query()->create([
            'period' => '2026-04-30',
            'branch_office_id' => $branch->id,
            'status' => CkpnWorkpaper::STATUS_GENERATION_QUEUED,
        ]);

        (new GenerateCkpnWorkpaperJob($workpaper->id))->handle(app(GenerateMonthlyCkpnWorkpaperAction::class));

        $this->assertSame(CkpnWorkpaper::STATUS_GENERATED, $workpaper->refresh()->status);
        $this->assertNotNull($workpaper->generated_at);
        $this->assertSame(1, $workpaper->items()->count());

        (new GenerateCkpnWorkpaperJob($workpaper->id))->handle(app(GenerateMonthlyCkpnWorkpaperAction::class));

        $this->assertSame(1, $workpaper->items()->count());

        $workpaper->forceFill(['status' => CkpnWorkpaper::STATUS_SUBMITTED])->save();
        (new GenerateCkpnWorkpaperJob($workpaper->id))->handle(app(GenerateMonthlyCkpnWorkpaperAction::class));

        $this->assertSame(CkpnWorkpaper::STATUS_SUBMITTED, $workpaper->refresh()->status);
        $this->assertSame(1, $workpaper->items()->count());
    }

    public function test_generate_job_failure_persists_failed_status_and_error(): void
    {
        $branch = BranchOffice::factory()->create(['branch_code' => '001']);
        InsuranceReceivable::factory()->create([
            'branch_office_id' => $branch->id,
            'branch_code' => $branch->branch_code,
            'receivable_formation_date' => '2026-04-01',
            'receivable_amount' => '10000.00',
        ]);
        $workpaper = CkpnWorkpaper::query()->create([
            'period' => '2026-04-01',
            'branch_office_id' => $branch->id,
            'status' => CkpnWorkpaper::STATUS_GENERATION_QUEUED,
        ]);

        try {
            (new GenerateCkpnWorkpaperJob($workpaper->id))->handle(app(GenerateMonthlyCkpnWorkpaperAction::class));

            $this->fail('Generation should fail without active CKPN calculation rule.');
        } catch (ValidationException) {
        }

        $this->assertSame(CkpnWorkpaper::STATUS_GENERATION_FAILED, $workpaper->refresh()->status);
        $this->assertStringContainsString('Active CKPN calculation rule not found', $workpaper->last_error_message);
        $this->assertSame(0, $workpaper->items()->count());
    }

    public function test_early_termination_resolved_receivable_is_eligible_for_ckpn_generation(): void
    {
        $this->seedDependencies();
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $this->receivable($branch, [
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_RESOLVED,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_RESOLVED,
            'receivable_formation_date' => '2026-04-30',
            'receivable_amount' => '10000.00',
        ]);
        $workpaper = CkpnWorkpaper::query()->create([
            'period' => '2026-04-30',
            'branch_office_id' => $branch->id,
        ]);

        $workpaper = app(GenerateMonthlyCkpnWorkpaperAction::class)->handle($workpaper);

        $this->assertSame(CkpnWorkpaper::STATUS_GENERATED, $workpaper->status);
        $this->assertSame(1, $workpaper->items()->count());
    }

    public function test_submit_is_blocked_without_generated_items(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('accounting_maker', '000');
        $workpaper = CkpnWorkpaper::query()->create([
            'period' => '2026-04-01',
            'status' => CkpnWorkpaper::STATUS_GENERATED,
        ]);

        $this->expectException(ValidationException::class);

        app(SubmitCkpnWorkpaperAction::class)->handle($workpaper, $maker);
    }

    public function test_draft_adjustment_blocks_journal_with_count_and_can_be_cancelled(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('accounting_maker', '000');
        $accountingMaker = $this->userWithRole('accounting_maker', '000');
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $this->receivable($branch, ['receivable_formation_date' => '2026-01-01', 'receivable_amount' => '10000.00']);
        $workpaper = CkpnWorkpaper::query()->create(['period' => '2026-06-30', 'branch_office_id' => $branch->id]);
        $workpaper = app(GenerateMonthlyCkpnWorkpaperAction::class)->handle($workpaper);
        $workpaper->forceFill(['status' => CkpnWorkpaper::STATUS_APPROVED])->save();
        $item = $workpaper->items()->sole();
        $payload = app(PrepareCkpnAdjustmentDataAction::class)->handle([
            'ckpn_workpaper_item_id' => $item->id,
            'requested_adjusted_ckpn_amount' => '200.00',
            'reason' => 'Draft blocker',
        ], $maker);
        $adjustment = CkpnAdjustment::query()->create($payload);

        try {
            app(CreateCkpnJournalFromWorkpaperAction::class)->handle($workpaper->refresh(), $accountingMaker);

            $this->fail('Draft adjustment should block CKPN journal creation.');
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            $this->assertStringContainsString('1 CKPN Adjustments', $message);
            $this->assertStringContainsString("#{$adjustment->id} (draft)", $message);
        }

        app(CancelCkpnAdjustmentAction::class)->handle($adjustment, $maker);

        $journal = app(CreateCkpnJournalFromWorkpaperAction::class)->handle($workpaper->refresh(), $accountingMaker);

        $this->assertSame(CkpnAdjustment::STATUS_CANCELLED, $adjustment->refresh()->status);
        $this->assertSame($workpaper->total_effective_ckpn_amount, $journal->total_amount);
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
            'claim_type' => InsuranceCompany::CLAIM_TYPE_AJK,
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
    private function legacyReceivable(BranchOffice $branch, array $attributes = []): InsuranceReceivable
    {
        return InsuranceReceivable::factory()->legacy()->create([
            'branch_office_id' => $branch->id,
            'branch_code' => $branch->branch_code,
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
