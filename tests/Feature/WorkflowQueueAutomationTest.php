<?php

namespace Tests\Feature;

use App\Actions\CkpnJournal\ApproveCkpnJournalAction;
use App\Actions\CkpnJournal\ExecuteGlToGlTransferAction;
use App\Actions\CkpnJournal\SubmitCkpnJournalAction;
use App\Actions\ClaimStatusChangeRequest\ApproveClaimStatusChangeRequestAction;
use App\Actions\ClaimStatusChangeRequest\CreateAndSubmitClaimStatusChangeFromReceivableAction;
use App\Actions\InsuranceReceivable\ApproveInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\AutoSubmitInsuranceReceivableForBranchApprovalAction;
use App\Actions\InsuranceReceivable\CancelInsuranceReceivableAction;
use App\Actions\InsuranceReceivable\ConfirmCollectabilityChangeCompletedAction;
use App\Actions\InsuranceReceivable\CreateInsuranceReceivableAction;
use App\Actions\InsuranceReceivable\ExecuteEarlyTerminationWithRepaymentTopUpAction;
use App\Actions\InsuranceReceivable\PerformLoanInquiryAction;
use App\Actions\InsuranceReceivable\ResolveEarlyTerminationManuallyAction;
use App\Actions\InsuranceReceivable\ReturnInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\SubmitReceivableFormationValidationAction;
use App\Filament\Resources\InsuranceReceivables\InsuranceReceivableResource;
use App\Filament\Resources\InsuranceReceivables\Pages\EditInsuranceReceivable;
use App\Filament\Resources\InsuranceReceivables\Pages\ViewInsuranceReceivable;
use App\Filament\Resources\InsuranceReceivables\RelationManagers\StageLogsRelationManager;
use App\Jobs\ExecuteEarlyTerminationJob;
use App\Jobs\ExecuteGlToGlJob;
use App\Jobs\RunLoanInquiryJob;
use App\Models\ApiIntegrationLog;
use App\Models\ApprovalRequest;
use App\Models\BranchOffice;
use App\Models\CkpnJournal;
use App\Models\CkpnWorkpaper;
use App\Models\ClaimStatus;
use App\Models\ClaimStatusChangeRequest;
use App\Models\EarlyTerminationTransaction;
use App\Models\GlToGlTransaction;
use App\Models\InsuranceCompany;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowQueueAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_creating_receivable_queues_inquiry_and_writes_stage_logs(): void
    {
        $this->seedDependencies();
        Queue::fake();
        $maker = $this->userWithRole('branch_maker', '001');

        $receivable = app(CreateInsuranceReceivableAction::class)->handle([
            'loan_account_number' => '3010001000054745',
            'date_of_death' => '2026-05-01',
            'insurance_company_id' => InsuranceCompany::query()->firstOrFail()->id,
        ], $maker);

        Queue::assertPushed(RunLoanInquiryJob::class, fn (RunLoanInquiryJob $job): bool => $job->insuranceReceivableId === $receivable->id);
        $this->assertSame(InsuranceReceivable::SYSTEM_STATUS_INQUIRY_QUEUED, $receivable->refresh()->system_status);
        $this->assertCount(0, $receivable->documents);
        $this->assertSame(['record_created', 'inquiry_queued'], $receivable->stageLogs()->pluck('event')->reverse()->values()->all());
    }

    public function test_creating_receivable_allows_documents_to_be_uploaded_later(): void
    {
        $this->seedDependencies();
        Queue::fake();
        $maker = $this->userWithRole('branch_maker', '001');

        $receivable = app(CreateInsuranceReceivableAction::class)->handle([
            'loan_account_number' => '3010001000054745',
            'date_of_death' => '2026-05-01',
            'insurance_company_id' => InsuranceCompany::query()->firstOrFail()->id,
        ], $maker);

        $this->assertSame(InsuranceReceivable::SYSTEM_STATUS_INQUIRY_QUEUED, $receivable->system_status);
        $this->assertCount(0, $receivable->documents);
    }

    public function test_inquiry_job_success_maps_response_and_writes_status_log(): void
    {
        config(['core_banking.base_url' => 'http://core.test', 'core_banking.signature_secret' => 'secret-key']);
        $this->seedDependencies();
        $maker = $this->userWithRole('branch_maker', '001');
        $receivable = $this->receivableFor($maker);

        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response([
                'responseCode' => '00',
                'description' => 'Success',
                'data' => [
                    'branchCode' => '001',
                    'loanOutStanding' => '230929055.00',
                    'accountNumber' => $receivable->loan_account_number,
                    'altNumber' => 'ALT-1',
                    'cifNo' => 'CIF-1',
                    'customerName' => 'Jane Customer',
                ],
            ]),
        ]);

        $this->runInquiryJob($receivable);

        $receivable = $receivable->refresh();
        $this->assertSame(InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED, $receivable->system_status);
        $this->assertSame('Jane Customer', $receivable->customer_name);
        $this->assertSame('230929055.00', $receivable->loan_outstanding);
        $this->assertNotNull($receivable->inquiry_completed_at);
        $this->assertTrue($receivable->stageLogs()->where('event', 'inquiry_completed')->exists());
        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED, $receivable->workflow_status);
        $this->assertTrue($receivable->approvalRequests()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH)
            ->exists());
    }

    public function test_inquiry_branch_mismatch_blocks_submission(): void
    {
        config(['core_banking.base_url' => 'http://core.test', 'core_banking.signature_secret' => 'secret-key']);
        $this->seedDependencies();
        $maker = $this->userWithRole('branch_maker', '001');
        $receivable = $this->receivableFor($maker);

        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response([
                'responseCode' => '00',
                'description' => 'Success',
                'data' => [
                    'branchCode' => '002',
                    'loanOutStanding' => '230929055.00',
                    'accountNumber' => $receivable->loan_account_number,
                ],
            ]),
        ]);

        $this->runInquiryJob($receivable);

        $this->assertSame(InsuranceReceivable::SYSTEM_STATUS_BRANCH_VALIDATION_FAILED, $receivable->refresh()->system_status);
        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_CANCELLED, $receivable->workflow_status);
        $this->assertTrue($receivable->stageLogs()->where('event', 'branch_validation_failed')->exists());

        Livewire::actingAs($maker)
            ->test(ViewInsuranceReceivable::class, ['record' => $receivable->id])
            ->assertActionHidden('retryInquiry')
            ->assertActionHidden('cancelReceivable');
    }

    public function test_branch_returned_receivable_save_queues_reinquiry(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('branch_maker', '001');
        $approver = $this->userWithRole('branch_approver', '001');
        $receivable = $this->receivableReadyForSubmit($maker);

        app(AutoSubmitInsuranceReceivableForBranchApprovalAction::class)->handle($receivable, $maker);
        $returned = app(ReturnInsuranceReceivableApprovalAction::class)->handle($receivable->refresh(), $approver, 'revise');

        Queue::fake();

        Livewire::actingAs($maker)
            ->test(EditInsuranceReceivable::class, ['record' => $returned->id])
            ->set('data.cif_no', 'CIF-REVISED')
            ->call('save')
            ->assertHasNoFormErrors();

        Queue::assertPushed(RunLoanInquiryJob::class, fn (RunLoanInquiryJob $job): bool => $job->insuranceReceivableId === $returned->id);
        $this->assertSame(InsuranceReceivable::SYSTEM_STATUS_INQUIRY_QUEUED, $returned->refresh()->system_status);
        $this->assertTrue($returned->stageLogs()->where('event', 'branch_return_reinquiry_queued')->exists());
    }

    public function test_branch_returned_receivable_resubmits_after_successful_reinquiry(): void
    {
        config(['core_banking.base_url' => 'http://core.test', 'core_banking.signature_secret' => 'secret-key']);
        $this->seedDependencies();
        $maker = $this->userWithRole('branch_maker', '001');
        $approver = $this->userWithRole('branch_approver', '001');
        $receivable = $this->receivableReadyForSubmit($maker);

        $submitted = app(AutoSubmitInsuranceReceivableForBranchApprovalAction::class)->handle($receivable, $maker);
        $originalRequest = $submitted->approvalRequests()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH)
            ->sole();
        $returned = app(ReturnInsuranceReceivableApprovalAction::class)->handle($submitted->refresh(), $approver, 'revise');

        Queue::fake();

        Livewire::actingAs($maker)
            ->test(EditInsuranceReceivable::class, ['record' => $returned->id])
            ->set('data.cif_no', 'CIF-REVISED')
            ->call('save')
            ->assertHasNoFormErrors();

        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response([
                'responseCode' => '00',
                'description' => 'Success',
                'data' => [
                    'branchCode' => '001',
                    'loanOutStanding' => '230929055.00',
                    'accountNumber' => $returned->loan_account_number,
                    'altNumber' => 'ALT-1',
                    'cifNo' => 'CIF-REVISED',
                    'customerName' => 'Jane Customer',
                ],
            ]),
        ]);

        $this->runInquiryJob($returned->refresh());

        $this->assertSame(ApprovalRequest::STATUS_RETURNED, $originalRequest->refresh()->status);
        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED, $returned->refresh()->workflow_status);
        $this->assertSame(2, $returned->approvalRequests()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH)
            ->count());
        $this->assertSame(1, $returned->approvalRequests()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH)
            ->where('status', ApprovalRequest::STATUS_SUBMITTED)
            ->count());
    }

    public function test_technical_inquiry_failure_can_be_cancelled_then_operational_actions_hide(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('branch_maker', '001');
        $receivable = $this->receivableFor($maker, [
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_INQUIRY_FAILED,
        ]);

        Livewire::actingAs($maker)
            ->test(ViewInsuranceReceivable::class, ['record' => $receivable->id])
            ->assertActionVisible('retryInquiry')
            ->assertActionVisible('cancelReceivable');

        $cancelled = app(CancelInsuranceReceivableAction::class)->handle($receivable, $maker, 'Cannot complete inquiry.');

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_CANCELLED, $cancelled->workflow_status);
        $this->assertTrue($cancelled->stageLogs()->where('event', 'technical_inquiry_failure_cancelled')->exists());

        Livewire::actingAs($maker)
            ->test(ViewInsuranceReceivable::class, ['record' => $receivable->id])
            ->assertActionHidden('retryInquiry')
            ->assertActionHidden('cancelReceivable')
            ->assertActionHidden('approveApproval')
            ->assertActionHidden('rejectApproval')
            ->assertActionHidden('returnApproval');
    }

    public function test_accounting_approval_waits_for_early_termination_confirmation(): void
    {
        config([
            'core_banking.base_url' => 'http://core.test',
            'core_banking.signature_secret' => 'secret-key',
            'services.contract_outstanding.base_url' => 'http://contract.test',
            'services.contract_outstanding.endpoint' => '/api/slik/inquiry',
            'services.contract_outstanding.token' => 'test-token',
            'services.contract_outstanding.retry_times' => 0,
        ]);
        $this->seedDependencies();
        $maker = $this->userWithRole('branch_maker', '001');
        $branchApprover = $this->userWithRole('branch_approver', '001');
        $accountingMaker = $this->userWithRole('accounting_maker', '000');
        $accountingApprover = $this->userWithRole('accounting_approver', '000');
        $receivable = $this->receivableReadyForSubmit($maker, [
            'date_of_death' => '2026-05-01',
            'loan_outstanding' => '230929055.00',
        ]);

        app(AutoSubmitInsuranceReceivableForBranchApprovalAction::class)->handle($receivable, $maker);
        $receivable = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable->refresh(), $branchApprover);
        $receivable = app(ConfirmCollectabilityChangeCompletedAction::class)->handle($receivable, $this->userWithRole('it_user', '000'));
        app(SubmitReceivableFormationValidationAction::class)->handle($receivable, $accountingMaker, [
            'journal_date' => '2026-05-31',
            'amount' => '230929055.00',
        ]);

        Queue::fake();
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response([
                'responseCode' => '00',
                'description' => 'Success',
                'data' => [
                    'accountNumber' => $receivable->loan_account_number,
                    'altNumber' => 'ALT-1',
                    'branchCode' => '001',
                    'collectability' => '1',
                    'dpd' => 0,
                    'saForLoanRepayment' => '1000010000000691',
                    'loanOutStanding' => '230929055.00',
                    'installmentAmount' => '1000.00',
                    'nextDueDate' => '20260501',
                ],
            ]),
            'http://contract.test/api/slik/inquiry' => Http::response($this->contractResponse('230929055', $receivable->loan_account_number)),
        ]);
        $receivable = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable->refresh(), $accountingApprover);

        Queue::assertNotPushed(ExecuteEarlyTerminationJob::class);
        $this->assertSame(InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_CONFIRMATION_PENDING, $receivable->system_status);
        $this->assertTrue($receivable->stageLogs()->where('event', 'early_termination_confirmation_pending')->exists());
    }

    public function test_early_termination_job_failure_then_retry_uses_new_reference(): void
    {
        config(['core_banking.base_url' => 'http://core.test', 'core_banking.signature_secret' => 'secret-key']);
        Carbon::setTestNow('2026-05-31 10:20:30');
        $this->seedDependencies();
        $maker = $this->userWithRole('branch_maker', '001');
        $receivable = $this->receivableReadyForSubmit($maker, [
            'loan_outstanding' => '230929055.00',
            'contract_outstanding_amount' => '230929055.00',
            'contract_outstanding_requested_as_of' => '2026-05-31',
            'contract_outstanding_as_of' => '2026-05-31',
            'contract_outstanding_product_code' => '301',
            'contract_outstanding_trx_type' => 'LSA01',
            'alt_number' => 'ALT-1',
            'saving_account_for_loan_repayment' => '1000010000000691',
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_QUEUED,
        ]);

        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response([
                'responseCode' => '00',
                'description' => 'Success',
                'data' => [
                    'accountNumber' => $receivable->loan_account_number,
                    'altNumber' => 'ALT-1',
                    'branchCode' => '001',
                    'saForLoanRepayment' => '1000010000000691',
                    'loanOutStanding' => '230929055.00',
                ],
            ]),
            'http://core.test/saving/inq/balance*' => Http::response([
                'responseCode' => '00',
                'description' => 'Success',
                'data' => ['availableBalance' => '230929055.00'],
            ]),
            'http://core.test/loan/earlytermination/' => Http::sequence()
                ->push(['responseCode' => '99', 'description' => 'Temporary failure', 'data' => []])
                ->push(['responseCode' => '00', 'description' => 'Success', 'data' => ['transactionId' => 'TRX-1']]),
        ]);

        (new ExecuteEarlyTerminationJob($receivable->id, $maker->id))->handle(
            app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class),
            app(InsuranceReceivableStageLogger::class),
        );

        $first = EarlyTerminationTransaction::query()->sole();
        $this->assertSame(EarlyTerminationTransaction::STATUS_FAILED, $first->status);
        $this->assertSame(InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_FAILED, $receivable->refresh()->system_status);

        (new ExecuteEarlyTerminationJob($receivable->id, $maker->id))->handle(
            app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class),
            app(InsuranceReceivableStageLogger::class),
        );

        $second = EarlyTerminationTransaction::query()->latest('id')->firstOrFail();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('ETERM-'.$receivable->id.'-001', $first->trx_reference);
        $this->assertSame('ETERM-'.$receivable->id.'-002', $second->trx_reference);
        $this->assertSame(EarlyTerminationTransaction::STATUS_FAILED, $first->refresh()->status);
        $this->assertSame(EarlyTerminationTransaction::STATUS_SUCCESS, $second->status);
        $this->assertSame(InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_EXECUTED, $receivable->refresh()->system_status);
    }

    public function test_early_termination_failure_can_be_resolved_manually_and_remains_non_terminal(): void
    {
        config(['core_banking.base_url' => 'http://core.test', 'core_banking.signature_secret' => 'secret-key']);
        $this->seedDependencies();
        $accountingApprover = $this->userWithRole('accounting_approver', '000');
        $businessMaker = $this->userWithRole('business_maker', '000');
        $receivable = $this->receivableReadyForSubmit($this->userWithRole('branch_maker', '001'), [
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_FAILED,
            'receivable_formation_date' => '2026-05-31',
            'receivable_amount' => '1000.00',
        ]);
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response([
                'responseCode' => '77',
                'description' => 'Data Not Found',
                'data' => [],
            ]),
        ]);

        $resolved = app(ResolveEarlyTerminationManuallyAction::class)->handle($receivable, $accountingApprover);

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_RESOLVED, $resolved->workflow_status);
        $this->assertSame(InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_RESOLVED, $resolved->system_status);
        $this->assertFalse($resolved->isTerminal());
        $this->assertTrue($resolved->stageLogs()->where('event', 'early_termination_resolved_after_manual_core_execution')->exists());

        Livewire::actingAs($businessMaker)
            ->test(ViewInsuranceReceivable::class, ['record' => $resolved->id])
            ->assertActionVisible('updateClaimStatus');
    }

    public function test_ckpn_journal_approval_queues_gl_to_gl_job(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('accounting_maker', '000');
        $approver = $this->userWithRole('accounting_approver', '000');
        $journal = $this->submittedCkpnJournal($maker);

        Queue::fake();
        $journal = app(ApproveCkpnJournalAction::class)->handle($journal, $approver);

        Queue::assertPushed(ExecuteGlToGlJob::class, fn (ExecuteGlToGlJob $job): bool => $job->ckpnJournalId === $journal->id);
        $this->assertSame(CkpnJournal::STATUS_GL_TO_GL_QUEUED, $journal->status);
    }

    public function test_gl_to_gl_job_failure_stores_raw_response_and_uses_new_refs(): void
    {
        config(['core_banking.base_url' => 'http://core.test', 'core_banking.signature_secret' => 'secret-key']);
        Carbon::setTestNow('2026-05-31 10:20:30');
        $this->seedDependencies();
        $maker = $this->userWithRole('accounting_maker', '000');
        $journal = $this->submittedCkpnJournal($maker);
        $journal->forceFill(['status' => CkpnJournal::STATUS_GL_TO_GL_QUEUED])->save();

        Http::fake([
            'http://core.test/trx/transfer/gl-to-gl' => Http::sequence()
                ->push(['unexpected' => ['reason' => 'temporary']])
                ->push(['responseCode' => '00', 'description' => 'Success', 'unexpected' => ['voucher' => 'V-1']]),
        ]);

        (new ExecuteGlToGlJob($journal->id, $maker->id))->handle(app(ExecuteGlToGlTransferAction::class));

        $first = GlToGlTransaction::query()->sole();
        $this->assertSame(GlToGlTransaction::STATUS_FAILED, $first->status);
        $this->assertSame(CkpnJournal::STATUS_GL_TO_GL_FAILED, $journal->refresh()->status);
        $this->assertSame('temporary', $first->response_payload['data']['unexpected']['reason']);

        (new ExecuteGlToGlJob($journal->id, $maker->id))->handle(app(ExecuteGlToGlTransferAction::class));

        $second = GlToGlTransaction::query()->latest('id')->firstOrFail();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('CKPNJ-'.$journal->id.'-001', $first->reference_number);
        $this->assertSame('CKPNJ-'.$journal->id.'-002', $second->reference_number);
        $this->assertSame($second->reference_number, $second->receipt_number);
        $this->assertSame(GlToGlTransaction::STATUS_FAILED, $first->refresh()->status);
        $this->assertSame(GlToGlTransaction::STATUS_SUCCESS, $second->status);
        $this->assertSame(CkpnJournal::STATUS_GL_TO_GL_EXECUTED, $journal->refresh()->status);
        $this->assertSame('V-1', $second->response_payload['data']['unexpected']['voucher']);

        $this->assertSame('[masked]', ApiIntegrationLog::query()->latest('id')->firstOrFail()->request_headers['Signature']);
    }

    public function test_view_page_exposes_workflow_actions_and_edit_page_hides_them(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('branch_maker', '001');
        $receivable = $this->receivableReadyForSubmit($maker);

        Livewire::actingAs($maker)
            ->test(ViewInsuranceReceivable::class, ['record' => $receivable->id])
            ->assertActionDoesNotExist('submitForApproval');

        Livewire::actingAs($maker)
            ->test(EditInsuranceReceivable::class, ['record' => $receivable->id])
            ->assertActionDoesNotExist('submitForApproval')
            ->assertActionDoesNotExist('approveApproval')
            ->assertActionDoesNotExist('rejectApproval')
            ->assertActionDoesNotExist('returnApproval')
            ->assertActionDoesNotExist('retryInquiry')
            ->assertActionDoesNotExist('retryEarlyTermination');
    }

    public function test_stage_logs_relation_manager_is_registered(): void
    {
        $this->assertContains(StageLogsRelationManager::class, InsuranceReceivableResource::getRelations());
    }

    public function test_view_page_renders_labeled_action_group_buttons(): void
    {
        $this->seedDependencies();
        $superAdmin = $this->userWithRole('super_admin', '000');
        $receivable = $this->receivableReadyForSubmit($this->userWithRole('branch_maker', '001'));
        $receivable->forceFill([
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
        ])->save();

        Livewire::actingAs($superAdmin)
            ->test(ViewInsuranceReceivable::class, ['record' => $receivable->id])
            ->assertSee('Approval')
            ->assertSee('System')
            ->assertSee('Claim Status');
    }

    public function test_claim_status_update_can_be_submitted_and_approved_from_receivable_view(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('business_maker', '000');
        $approver = $this->userWithRole('business_approver', '000');
        $receivable = $this->receivableReadyForSubmit($this->userWithRole('branch_maker', '001'));
        $originalStatusId = $receivable->claim_status_id;
        $targetStatus = ClaimStatus::query()->where('code', 'approved')->firstOrFail();

        Livewire::actingAs($maker)
            ->test(ViewInsuranceReceivable::class, ['record' => $receivable->id])
            ->assertActionVisible('updateClaimStatus');

        app(CreateAndSubmitClaimStatusChangeFromReceivableAction::class)->handle($receivable, $maker, [
            'to_claim_status_id' => $targetStatus->id,
            'reason' => 'Insurance approved claim.',
        ]);

        $request = $receivable->claimStatusChangeRequests()->sole();
        $this->assertSame(ClaimStatusChangeRequest::STATUS_SUBMITTED, $request->status);
        $this->assertSame($originalStatusId, $receivable->refresh()->claim_status_id);
        $this->assertTrue($receivable->stageLogs()->where('event', 'claim_status_update_requested')->exists());

        Livewire::actingAs($approver)
            ->test(ViewInsuranceReceivable::class, ['record' => $receivable->id])
            ->assertActionVisible('approveClaimStatusUpdate');

        app(ApproveClaimStatusChangeRequestAction::class)->handle($request, $approver);

        $this->assertSame($targetStatus->id, $receivable->refresh()->claim_status_id);
        $this->assertSame(ClaimStatusChangeRequest::STATUS_APPROVED, $request->refresh()->status);
        $this->assertTrue($receivable->stageLogs()->where('event', 'claim_status_update_approved')->exists());
    }

    private function runInquiryJob(InsuranceReceivable $receivable): void
    {
        (new RunLoanInquiryJob($receivable->id))->handle(
            app(PerformLoanInquiryAction::class),
            app(AutoSubmitInsuranceReceivableForBranchApprovalAction::class),
            app(InsuranceReceivableStageLogger::class),
        );
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
            'loan_account_number' => '3010001000054745',
            'death_document_condition' => InsuranceReceivable::DEATH_DOCUMENT_CONDITION_HOSPITAL,
            'saving_account_for_loan_repayment' => '1000010000000691',
            'created_by' => $user->id,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function receivableReadyForSubmit(User $user, array $attributes = []): InsuranceReceivable
    {
        $receivable = $this->receivableFor($user, $attributes);
        $receivable->forceFill([
            'system_status' => $attributes['system_status'] ?? InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED,
            'inquiry_completed_at' => now(),
        ])->saveQuietly();

        return $receivable->refresh();
    }

    private function submittedCkpnJournal(User $maker): CkpnJournal
    {
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $workpaper = CkpnWorkpaper::query()->create([
            'period' => '2026-05-31',
            'branch_office_id' => $branch->id,
            'status' => CkpnWorkpaper::STATUS_APPROVED,
            'total_receivable_amount' => '1000000.00',
            'total_calculated_ckpn_amount' => '1000.00',
            'total_adjustment_delta' => '0.00',
            'total_effective_ckpn_amount' => '1000.00',
            'total_ckpn_amount' => '1000.00',
        ]);
        $journal = CkpnJournal::query()->create([
            'ckpn_workpaper_id' => $workpaper->id,
            'branch_office_id' => $branch->id,
            'journal_date' => '2026-05-31',
            'total_amount' => '1000.00',
            'status' => CkpnJournal::STATUS_DRAFT,
        ]);

        return app(SubmitCkpnJournalAction::class)->handle($journal, $maker);
    }

    private function contractResponse(string $bakiDebet, string $accountNumber): array
    {
        return [
            'result' => [
                'AccountNumber' => $accountNumber,
                'AsOf' => '2026-05-31T00:00:00Z',
                'BakiDebet' => $bakiDebet,
            ],
            'loan' => [
                'AccountNumber' => $accountNumber,
                'Product' => '301 - Kredit Pegawai Aktif',
            ],
        ];
    }

    private function userWithRole(string $role, string $branchCode): User
    {
        $branchOffice = BranchOffice::query()->where('branch_code', $branchCode)->firstOrFail();
        $user = User::factory()->create(['branch_office_id' => $branchOffice->id]);
        $user->assignRole($role);

        return $user;
    }
}
