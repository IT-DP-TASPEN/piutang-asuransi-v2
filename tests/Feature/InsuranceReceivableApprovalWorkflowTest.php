<?php

namespace Tests\Feature;

use App\Actions\InsuranceReceivable\ApproveInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\RejectInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\ReturnInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\SubmitInsuranceReceivableForApprovalAction;
use App\Actions\InsuranceReceivable\SubmitReceivableFormationValidationAction;
use App\Actions\InsuranceReceivable\UpdateCollectabilityAction;
use App\Models\ApprovalLog;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\BranchOffice;
use App\Models\InsuranceReceivable;
use App\Models\InsuranceReceivableFieldChangeLog;
use App\Models\ReceivableFormationJournal;
use App\Models\User;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsuranceReceivableApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_submission_creates_approval_request_step_log_and_updates_receivable(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('branch_maker', '001');
        $receivable = $this->receivableFor($maker);

        $result = app(SubmitInsuranceReceivableForApprovalAction::class)->handle($receivable, $maker, 'submit claim');

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED, $result->workflow_status);

        $request = ApprovalRequest::query()->sole();
        $this->assertSame(ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH, $request->workflow_code);
        $this->assertSame(ApprovalRequest::STATUS_SUBMITTED, $request->status);
        $this->assertSame($maker->id, $request->submitted_by);

        $step = ApprovalStep::query()->sole();
        $this->assertSame('branch_approver', $step->role_name);
        $this->assertSame(ApprovalStep::STATUS_PENDING, $step->status);

        $this->assertSame('submitted', ApprovalLog::query()->sole()->action);
    }

    public function test_branch_and_accounting_approval_flow_finalizes_receivable_formation(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('branch_maker', '001');
        $branchApprover = $this->userWithRole('branch_approver', '001');
        $accountingMaker = $this->userWithRole('accounting_maker', '000');
        $accountingApprover = $this->userWithRole('accounting_approver', '000');
        $receivable = $this->receivableFor($maker, [
            'loan_outstanding' => '230929055.00',
        ]);

        app(SubmitInsuranceReceivableForApprovalAction::class)->handle($receivable, $maker);
        $receivable = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable->refresh(), $branchApprover);

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_BRANCH_APPROVED, $receivable->workflow_status);

        $receivable = app(SubmitReceivableFormationValidationAction::class)->handle($receivable, $accountingMaker, [
            'journal_date' => '2026-05-31',
            'amount' => '230929055.00',
            'debit_account' => null,
            'credit_account' => null,
            'description' => 'Receivable formation',
        ]);

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION, $receivable->workflow_status);

        $receivable = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $accountingApprover);
        $journal = ReceivableFormationJournal::query()->sole();

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED, $receivable->workflow_status);
        $this->assertSame('2026-05-31', $receivable->receivable_formation_date?->toDateString());
        $this->assertSame('230929055.00', $receivable->receivable_amount);
        $this->assertSame(ReceivableFormationJournal::STATUS_APPROVED, $journal->status);
        $this->assertSame($accountingApprover->id, $journal->approved_by);
        $this->assertSame(2, ApprovalRequest::query()->count());
    }

    public function test_reject_and_return_update_approval_and_receivable_status(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('branch_maker', '001');
        $approver = $this->userWithRole('branch_approver', '001');

        $returned = $this->receivableFor($maker);
        app(SubmitInsuranceReceivableForApprovalAction::class)->handle($returned, $maker);
        $returned = app(ReturnInsuranceReceivableApprovalAction::class)->handle($returned->refresh(), $approver, 'revise');

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_RETURNED, $returned->workflow_status);
        $this->assertSame(ApprovalRequest::STATUS_RETURNED, ApprovalRequest::query()->firstOrFail()->status);

        $rejected = $this->receivableFor($maker);
        app(SubmitInsuranceReceivableForApprovalAction::class)->handle($rejected, $maker);
        $rejected = app(RejectInsuranceReceivableApprovalAction::class)->handle($rejected->refresh(), $approver, 'reject');

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_REJECTED, $rejected->workflow_status);
        $this->assertSame(ApprovalRequest::STATUS_REJECTED, ApprovalRequest::query()->latest('id')->firstOrFail()->status);
    }

    public function test_collectability_update_writes_field_change_log(): void
    {
        $this->seedDependencies();
        $itUser = $this->userWithRole('it_user', '000');
        $receivable = InsuranceReceivable::factory()->create([
            'collectability' => '1',
        ]);

        $result = app(UpdateCollectabilityAction::class)->handle($receivable, $itUser, '2', 'manual correction');
        $log = InsuranceReceivableFieldChangeLog::query()->sole();

        $this->assertSame('2', $result->collectability);
        $this->assertSame('collectability', $log->field_name);
        $this->assertSame('1', $log->old_value);
        $this->assertSame('2', $log->new_value);
        $this->assertSame($itUser->id, $log->changed_by);
        $this->assertSame('manual correction', $log->reason);
    }

    private function seedDependencies(): void
    {
        $this->seed([
            BranchOfficeSeeder::class,
            InsuranceCompanySeeder::class,
            ClaimStatusSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function receivableFor(User $user, array $attributes = []): InsuranceReceivable
    {
        return InsuranceReceivable::factory()->create([
            'branch_office_id' => $user->branch_office_id,
            'branch_code' => $user->branchOffice->branch_code,
            'created_by' => $user->id,
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
