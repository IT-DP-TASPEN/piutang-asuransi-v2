<?php

namespace Tests\Feature;

use App\Filament\Resources\ApprovalQueues\ApprovalQueueResource;
use App\Filament\Resources\ApprovalQueues\Pages\ListApprovalQueue;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\BranchOffice;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimDocumentSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ApprovalQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_auditor_can_monitor_all_pending_without_action_rights(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('branch_maker', '001');
        $auditor = $this->userWithRole('auditor', '000');
        $request = $this->submittedBranchRequest($maker, 'Audited Customer');

        Livewire::actingAs($auditor)
            ->test(ListApprovalQueue::class)
            ->assertSee('All Pending')
            ->set('activeTab', 'all_pending')
            ->assertSee('Audited Customer')
            ->assertTableActionHidden('approve', $request)
            ->assertTableActionHidden('return', $request)
            ->assertTableActionHidden('reject', $request);
    }

    public function test_normal_user_does_not_see_all_pending_tab(): void
    {
        $this->seedDependencies();
        $approver = $this->userWithRole('branch_approver', '001');

        Livewire::actingAs($approver)
            ->test(ListApprovalQueue::class)
            ->assertDontSee('All Pending');
    }

    public function test_my_pending_only_shows_actionable_branch_requests(): void
    {
        $this->seedDependencies();
        $makerOne = $this->userWithRole('branch_maker', '001');
        $makerTwo = $this->userWithRole('branch_maker', '002');
        $approver = $this->userWithRole('branch_approver', '001');
        $ownBranch = $this->submittedBranchRequest($makerOne, 'Visible Customer');
        $this->submittedBranchRequest($makerTwo, 'Hidden Customer');

        Livewire::actingAs($approver)
            ->test(ListApprovalQueue::class)
            ->assertSee('Visible Customer')
            ->assertDontSee('Hidden Customer')
            ->assertTableActionVisible('approve', $ownBranch)
            ->assertTableActionVisible('return', $ownBranch)
            ->assertTableActionVisible('reject', $ownBranch);
    }

    public function test_my_requests_and_unknown_workflow_fallback_are_read_only(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('branch_maker', '001');
        $receivable = $this->receivableFor($maker, ['customer_name' => 'Fallback Customer']);

        /** @var ApprovalRequest $request */
        $request = $receivable->approvalRequests()->create([
            'workflow_code' => 'unknown_workflow',
            'status' => ApprovalRequest::STATUS_SUBMITTED,
            'submitted_by' => $maker->id,
            'submitted_at' => now(),
        ]);

        Livewire::actingAs($maker)
            ->test(ListApprovalQueue::class)
            ->set('activeTab', 'my_requests')
            ->assertSee('unknown_workflow')
            ->assertSee('Fallback Customer')
            ->assertTableActionHidden('approve', $request)
            ->assertTableActionHidden('return', $request)
            ->assertTableActionHidden('reject', $request);
    }

    public function test_history_is_limited_for_normal_users(): void
    {
        $this->seedDependencies();
        $user = $this->userWithRole('branch_maker', '001');
        $other = $this->userWithRole('branch_maker', '002');

        $this->terminalRequest($user, 'Own History');
        $acted = $this->terminalRequest($other, 'Acted History');
        $acted->steps()->firstOrFail()->forceFill([
            'status' => ApprovalStep::STATUS_APPROVED,
            'acted_by' => $user->id,
            'acted_at' => now(),
        ])->save();
        $this->terminalRequest($other, 'Other History');

        Livewire::actingAs($user)
            ->test(ListApprovalQueue::class)
            ->set('activeTab', 'history')
            ->assertSee('Own History')
            ->assertSee('Acted History')
            ->assertDontSee('Other History');
    }

    public function test_stale_request_action_is_blocked(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('branch_maker', '001');
        $approver = $this->userWithRole('branch_approver', '001');
        $request = $this->submittedBranchRequest($maker, 'Stale Customer');

        $request->forceFill(['status' => ApprovalRequest::STATUS_APPROVED])->save();

        $this->actingAs($approver);

        $this->assertFalse(ApprovalQueueResource::runQueueAction($request, 'approve'));
        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED, $request->approvable->refresh()->workflow_status);
    }

    public function test_rendering_manual_et_queue_does_not_call_http(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('accounting_maker', '000');
        $approver = $this->userWithRole('accounting_approver', '000');
        $receivable = $this->receivableFor($maker, [
            'customer_name' => 'Manual ET Customer',
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_SUBMITTED,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED,
        ]);

        app(ApprovalService::class)->submit(
            $receivable,
            ApprovalRequest::WORKFLOW_MANUAL_EARLY_TERMINATION_VERIFICATION,
            $maker,
        );

        Http::fake();

        Livewire::actingAs($approver)
            ->test(ListApprovalQueue::class)
            ->assertSee('Manual ET Customer');

        Http::assertNothingSent();
    }

    private function seedDependencies(): void
    {
        $this->seed([
            BranchOfficeSeeder::class,
            InsuranceCompanySeeder::class,
            ClaimStatusSeeder::class,
            ClaimDocumentSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    private function submittedBranchRequest(User $maker, string $customerName): ApprovalRequest
    {
        $receivable = $this->receivableFor($maker, [
            'customer_name' => $customerName,
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED,
            'loan_outstanding' => '1000000.00',
            'receivable_amount' => '1000000.00',
        ]);

        return app(ApprovalService::class)->submit(
            $receivable,
            ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH,
            $maker,
        );
    }

    private function terminalRequest(User $submitter, string $customerName): ApprovalRequest
    {
        $request = $this->submittedBranchRequest($submitter, $customerName);
        $request->forceFill(['status' => ApprovalRequest::STATUS_APPROVED, 'final_approved_at' => now()])->save();

        return $request->refresh();
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
            'saving_account_for_loan_repayment' => '1000010000000691',
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
