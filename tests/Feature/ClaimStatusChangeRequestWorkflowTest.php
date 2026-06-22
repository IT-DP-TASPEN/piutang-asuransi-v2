<?php

namespace Tests\Feature;

use App\Actions\ClaimStatusChangeRequest\ApproveClaimStatusChangeRequestAction;
use App\Actions\ClaimStatusChangeRequest\CancelClaimStatusChangeRequestAction;
use App\Actions\ClaimStatusChangeRequest\PrepareClaimStatusChangeRequestDataAction;
use App\Actions\ClaimStatusChangeRequest\RejectClaimStatusChangeRequestAction;
use App\Actions\ClaimStatusChangeRequest\SubmitClaimStatusChangeRequestAction;
use App\Actions\InsuranceCoverLetter\GenerateInsuranceCoverLetterAction;
use App\Models\ApprovalLog;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\BranchOffice;
use App\Models\ClaimStatus;
use App\Models\ClaimStatusChangeRequest;
use App\Models\InsuranceCoverLetter;
use App\Models\InsuranceReceivable;
use App\Models\User;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimDocumentSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\InsuranceCoverLetterSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClaimStatusChangeRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_submitting_claim_status_request_creates_approval_and_does_not_update_receivable_status(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('business_maker', '000');
        $receivable = InsuranceReceivable::factory()->create();
        $originalStatusId = $receivable->claim_status_id;
        $targetStatus = ClaimStatus::query()->where('code', 'approved')->firstOrFail();
        $request = $this->createDraftRequest($receivable, $targetStatus, $maker);

        $request = app(SubmitClaimStatusChangeRequestAction::class)->handle($request, $maker, 'submit status update');

        $this->assertSame(ClaimStatusChangeRequest::STATUS_SUBMITTED, $request->status);
        $this->assertSame($originalStatusId, $receivable->refresh()->claim_status_id);

        $approvalRequest = ApprovalRequest::query()->sole();
        $this->assertSame(ApprovalRequest::WORKFLOW_CLAIM_STATUS_UPDATE, $approvalRequest->workflow_code);
        $this->assertSame(ApprovalRequest::STATUS_SUBMITTED, $approvalRequest->status);
        $this->assertSame($maker->id, $approvalRequest->submitted_by);

        $step = ApprovalStep::query()->sole();
        $this->assertSame('business_approver', $step->role_name);
        $this->assertSame(ApprovalStep::STATUS_PENDING, $step->status);
        $this->assertSame('submitted', ApprovalLog::query()->sole()->action);
    }

    public function test_approving_claim_status_request_updates_receivable_status(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('business_maker', '000');
        $approver = $this->userWithRole('business_approver', '000');
        $receivable = InsuranceReceivable::factory()->create();
        $targetStatus = ClaimStatus::query()->where('code', 'reject_loss')->firstOrFail();
        $request = $this->createDraftRequest($receivable, $targetStatus, $maker);

        $request = app(SubmitClaimStatusChangeRequestAction::class)->handle($request, $maker);
        $request = app(ApproveClaimStatusChangeRequestAction::class)->handle($request, $approver, 'approved');

        $this->assertSame(ClaimStatusChangeRequest::STATUS_APPROVED, $request->status);
        $this->assertSame($approver->id, $request->approved_by);
        $this->assertNotNull($request->approved_at);
        $this->assertSame($targetStatus->id, $receivable->refresh()->claim_status_id);
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, ApprovalRequest::query()->sole()->status);
    }

    public function test_rejected_claim_status_request_does_not_update_receivable_status(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('business_maker', '000');
        $approver = $this->userWithRole('business_approver', '000');
        $receivable = InsuranceReceivable::factory()->create();
        $originalStatusId = $receivable->claim_status_id;
        $targetStatus = ClaimStatus::query()->where('code', 'reject_loss')->firstOrFail();
        $request = $this->createDraftRequest($receivable, $targetStatus, $maker);

        $request = app(SubmitClaimStatusChangeRequestAction::class)->handle($request, $maker);
        $request = app(RejectClaimStatusChangeRequestAction::class)->handle($request, $approver, 'not valid');

        $this->assertSame(ClaimStatusChangeRequest::STATUS_REJECTED, $request->status);
        $this->assertSame($originalStatusId, $receivable->refresh()->claim_status_id);
        $this->assertSame(ApprovalRequest::STATUS_REJECTED, ApprovalRequest::query()->sole()->status);
    }

    public function test_draft_claim_status_request_can_be_cancelled(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('business_maker', '000');
        $receivable = InsuranceReceivable::factory()->create();
        $targetStatus = ClaimStatus::query()->where('code', 'reject_loss')->firstOrFail();
        $request = $this->createDraftRequest($receivable, $targetStatus, $maker);

        $request = app(CancelClaimStatusChangeRequestAction::class)->handle($request, $maker, 'not needed');

        $this->assertSame(ClaimStatusChangeRequest::STATUS_CANCELLED, $request->status);
        $this->assertTrue($receivable->stageLogs()->where('event', 'claim_status_update_cancelled')->exists());
    }

    public function test_claim_status_request_uses_master_claim_status_rows(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('business_maker', '000');
        $approver = $this->userWithRole('business_approver', '000');
        $receivable = InsuranceReceivable::factory()->create();
        $customStatus = ClaimStatus::query()->create([
            'code' => 'custom_phase4_status',
            'name' => 'Custom Phase 4 Status',
            'ckpn_weight' => '12.5000',
            'is_default' => false,
            'is_terminal' => false,
            'is_active' => true,
        ]);
        $request = $this->createDraftRequest($receivable, $customStatus, $maker);

        $request = app(SubmitClaimStatusChangeRequestAction::class)->handle($request, $maker);
        app(ApproveClaimStatusChangeRequestAction::class)->handle($request, $approver);

        $this->assertSame('custom_phase4_status', $receivable->refresh()->claimStatus->code);
        $this->assertSame($customStatus->id, $request->refresh()->to_claim_status_id);
    }

    public function test_cover_letter_draft_can_be_generated_from_receivable(): void
    {
        $this->seedDependencies();
        Storage::fake('local');
        $user = $this->userWithRole('business_maker', '000');
        $receivable = InsuranceReceivable::factory()->create([
            'loan_account_number' => '3010010000000068',
            'customer_name' => 'Jane Customer',
            'date_of_death' => '2026-01-15',
            'loan_outstanding' => '230929055.00',
        ]);

        $letter = app(GenerateInsuranceCoverLetterAction::class)->handle($receivable, $user);

        $this->assertSame($receivable->id, $letter->insurance_receivable_id);
        $this->assertSame($receivable->insurance_company_id, $letter->insurance_company_id);
        $this->assertSame($user->id, $letter->created_by);
        $this->assertSame(InsuranceCoverLetter::STATUS_GENERATED, $letter->status);
        $this->assertSame('Pengajuan Klaim Asuransi 3010010000000068', $letter->subject);
        $this->assertStringContainsString('Jane Customer', $letter->rendered_html);
        $this->assertStringContainsString('Rp 230.929.055', $letter->rendered_html);
    }

    private function seedDependencies(): void
    {
        $this->seed([
            BranchOfficeSeeder::class,
            InsuranceCompanySeeder::class,
            ClaimStatusSeeder::class,
            ClaimDocumentSeeder::class,
            InsuranceCoverLetterSettingSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    private function createDraftRequest(InsuranceReceivable $receivable, ClaimStatus $targetStatus, User $maker): ClaimStatusChangeRequest
    {
        $data = app(PrepareClaimStatusChangeRequestDataAction::class)->handle([
            'insurance_receivable_id' => $receivable->id,
            'to_claim_status_id' => $targetStatus->id,
            'reason' => 'Phase 4 test',
        ], $maker);

        return ClaimStatusChangeRequest::query()->create($data);
    }

    private function userWithRole(string $role, string $branchCode): User
    {
        $branchOffice = BranchOffice::query()->where('branch_code', $branchCode)->firstOrFail();
        $user = User::factory()->create(['branch_office_id' => $branchOffice->id]);
        $user->assignRole($role);

        return $user;
    }
}
