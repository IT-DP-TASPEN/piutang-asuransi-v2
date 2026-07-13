<?php

namespace Tests\Feature;

use App\Actions\InsuranceReceivable\ApproveInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\AutoSubmitInsuranceReceivableForBranchApprovalAction;
use App\Actions\InsuranceReceivable\ConfirmCollectabilityChangeCompletedAction;
use App\Actions\InsuranceReceivable\RejectInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\ReturnInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\SubmitReceivableFormationValidationAction;
use App\Filament\Resources\InsuranceReceivables\InsuranceReceivableResource;
use App\Filament\Resources\InsuranceReceivables\Pages\ViewInsuranceReceivable;
use App\Models\ApprovalLog;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\BranchOffice;
use App\Models\ClaimDocumentType;
use App\Models\InsuranceReceivable;
use App\Models\User;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimDocumentSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class InsuranceReceivableApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_submission_creates_approval_request_step_log_and_updates_receivable(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('branch_maker', '001');
        $receivable = $this->receivableFor($maker);

        $result = app(AutoSubmitInsuranceReceivableForBranchApprovalAction::class)->handle($receivable, $maker);

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
            'date_of_death' => '2026-05-31',
            'loan_outstanding' => '230929055.00',
        ]);
        config([
            'core_banking.base_url' => 'http://core.test',
            'services.contract_outstanding.base_url' => 'http://contract.test',
            'services.contract_outstanding.endpoint' => '/api/slik/inquiry',
            'services.contract_outstanding.token' => 'test-token',
            'services.contract_outstanding.retry_times' => 0,
        ]);

        app(AutoSubmitInsuranceReceivableForBranchApprovalAction::class)->handle($receivable, $maker);
        $receivable = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable->refresh(), $branchApprover);

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING, $receivable->workflow_status);

        $receivable = app(ConfirmCollectabilityChangeCompletedAction::class)->handle($receivable, $this->userWithRole('it_user', '000'));

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION_PENDING, $receivable->workflow_status);

        $receivable = app(SubmitReceivableFormationValidationAction::class)->handle($receivable, $accountingMaker, [
            'notes' => 'Accounting maker notes',
        ]);

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION, $receivable->workflow_status);
        $approvalRequest = ApprovalRequest::query()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION)
            ->sole();

        $this->assertFalse(Schema::hasTable('receivable_formation_journals'));
        $this->assertNull(ApprovalLog::query()
            ->where('approval_request_id', $approvalRequest->id)
            ->where('action', 'submitted')
            ->firstOrFail()
            ->metadata);

        Livewire::actingAs($accountingApprover)
            ->test(ViewInsuranceReceivable::class, ['record' => $receivable->id])
            ->assertSee('Accounting validation')
            ->assertDontSee('Debit account')
            ->assertDontSee('Credit account');

        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response([
                'responseCode' => '00',
                'description' => 'SUCCESS',
                'data' => $this->loanInquiryData([
                    'accountNumber' => $receivable->loan_account_number,
                    'loanOutStanding' => '230929000.00',
                    'nextDueDate' => '20260531',
                ]),
            ]),
            'http://contract.test/api/slik/inquiry' => Http::response($this->contractResponse('230929000', $receivable->loan_account_number)),
        ]);

        $receivable = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $accountingApprover);

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED, $receivable->workflow_status);
        $this->assertSame('230929000.00', $receivable->receivable_amount);
        $this->assertSame('230929000.00', $receivable->remaining_receivable_amount);
        $this->assertTrue($receivable->stageLogs()->where('event', 'accounting_validation_approved')->exists());
        $this->assertSame(2, ApprovalRequest::query()->count());
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://core.test/inquiry/detail/loan');
    }

    public function test_auto_submit_refuses_terminal_or_already_submitted_records_without_duplicate_active_request(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('branch_maker', '001');
        $receivable = $this->receivableFor($maker);

        app(AutoSubmitInsuranceReceivableForBranchApprovalAction::class)->handle($receivable, $maker);
        $this->assertSame(1, $receivable->approvalRequests()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH)
            ->where('status', ApprovalRequest::STATUS_SUBMITTED)
            ->count());

        try {
            app(AutoSubmitInsuranceReceivableForBranchApprovalAction::class)->handle($receivable->refresh(), $maker);
            $this->fail('Already submitted receivable should not be auto-submitted again.');
        } catch (ValidationException) {
        }

        $this->assertSame(1, $receivable->approvalRequests()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH)
            ->where('status', ApprovalRequest::STATUS_SUBMITTED)
            ->count());

        $cancelled = $this->receivableFor($maker, [
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_CANCELLED,
        ]);

        $this->expectException(ValidationException::class);
        app(AutoSubmitInsuranceReceivableForBranchApprovalAction::class)->handle($cancelled, $maker);
    }

    public function test_reject_and_return_update_approval_and_receivable_status(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('branch_maker', '001');
        $approver = $this->userWithRole('branch_approver', '001');

        $returned = $this->receivableFor($maker);
        app(AutoSubmitInsuranceReceivableForBranchApprovalAction::class)->handle($returned, $maker);
        $returned = app(ReturnInsuranceReceivableApprovalAction::class)->handle($returned->refresh(), $approver, 'revise');

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER, $returned->workflow_status);
        $this->assertSame(ApprovalRequest::STATUS_RETURNED, ApprovalRequest::query()->firstOrFail()->status);

        $rejected = $this->receivableFor($maker);
        app(AutoSubmitInsuranceReceivableForBranchApprovalAction::class)->handle($rejected, $maker);
        $rejected = app(RejectInsuranceReceivableApprovalAction::class)->handle($rejected->refresh(), $approver, 'reject');

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_REJECTED, $rejected->workflow_status);
        $this->assertSame(ApprovalRequest::STATUS_REJECTED, ApprovalRequest::query()->latest('id')->firstOrFail()->status);
    }

    public function test_accounting_return_goes_back_to_accounting_maker_only(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('branch_maker', '001');
        $branchApprover = $this->userWithRole('branch_approver', '001');
        $accountingMaker = $this->userWithRole('accounting_maker', '000');
        $accountingApprover = $this->userWithRole('accounting_approver', '000');
        $receivable = $this->receivableFor($maker, ['loan_outstanding' => '1000.00']);

        app(AutoSubmitInsuranceReceivableForBranchApprovalAction::class)->handle($receivable, $maker);
        $receivable = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable->refresh(), $branchApprover);
        $receivable = app(ConfirmCollectabilityChangeCompletedAction::class)->handle($receivable, $this->userWithRole('it_user', '000'));
        $receivable = app(SubmitReceivableFormationValidationAction::class)->handle($receivable, $accountingMaker);

        $returned = app(ReturnInsuranceReceivableApprovalAction::class)->handle($receivable, $accountingApprover, 'revise');

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_ACCOUNTING_MAKER, $returned->workflow_status);
        $this->assertSame(ApprovalRequest::STATUS_RETURNED, ApprovalRequest::query()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION)
            ->sole()
            ->status);
    }

    public function test_accounting_validation_submit_creates_approval_only(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('branch_maker', '001');
        $branchApprover = $this->userWithRole('branch_approver', '001');
        $accountingMaker = $this->userWithRole('accounting_maker', '000');
        $receivable = $this->receivableFor($maker, ['loan_outstanding' => '1000.00']);

        app(AutoSubmitInsuranceReceivableForBranchApprovalAction::class)->handle($receivable, $maker);
        $receivable = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable->refresh(), $branchApprover);
        $receivable = app(ConfirmCollectabilityChangeCompletedAction::class)->handle($receivable, $this->userWithRole('it_user', '000'));
        $receivable = app(SubmitReceivableFormationValidationAction::class)->handle($receivable, $accountingMaker, [
            'notes' => 'reviewed',
        ]);

        $request = ApprovalRequest::query()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION)
            ->sole();

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION, $receivable->workflow_status);
        $this->assertSame(ApprovalRequest::STATUS_SUBMITTED, $request->status);
        $this->assertFalse(Schema::hasTable('receivable_formation_journals'));
        $this->assertSame('reviewed', $request->logs()->where('action', 'submitted')->sole()->notes);
    }

    public function test_accounting_validation_resubmit_after_return_creates_new_approval_request(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('branch_maker', '001');
        $branchApprover = $this->userWithRole('branch_approver', '001');
        $accountingMaker = $this->userWithRole('accounting_maker', '000');
        $accountingApprover = $this->userWithRole('accounting_approver', '000');
        $receivable = $this->receivableFor($maker, ['loan_outstanding' => '1000.00']);

        app(AutoSubmitInsuranceReceivableForBranchApprovalAction::class)->handle($receivable, $maker);
        $receivable = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable->refresh(), $branchApprover);
        $receivable = app(ConfirmCollectabilityChangeCompletedAction::class)->handle($receivable, $this->userWithRole('it_user', '000'));
        $receivable = app(SubmitReceivableFormationValidationAction::class)->handle($receivable, $accountingMaker);
        $receivable = app(ReturnInsuranceReceivableApprovalAction::class)->handle($receivable, $accountingApprover, 'revise');

        app(SubmitReceivableFormationValidationAction::class)->handle($receivable, $accountingMaker, [
            'notes' => 'second',
        ]);

        $requests = ApprovalRequest::query()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $requests);
        $this->assertSame(ApprovalRequest::STATUS_RETURNED, $requests[0]->status);
        $this->assertSame(ApprovalRequest::STATUS_SUBMITTED, $requests[1]->status);
        $this->assertSame('second', $requests[1]->logs()->where('action', 'submitted')->sole()->notes);
    }

    public function test_submitted_receivable_is_not_editable(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('branch_maker', '001');
        $receivable = $this->receivableFor($maker);

        $submitted = app(AutoSubmitInsuranceReceivableForBranchApprovalAction::class)->handle($receivable, $maker);

        $this->assertFalse($maker->can('update', $submitted));
    }

    public function test_branch_maker_can_edit_only_branch_safe_states_and_not_documents_after_accounting_stage(): void
    {
        $this->seedDependencies();
        $maker = $this->userWithRole('branch_maker', '001');
        $draft = $this->receivableFor($maker, [
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
        ]);
        $returnedToBranch = $this->receivableFor($maker, [
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER,
        ]);
        $accountingStage = $this->receivableFor($maker, [
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION_PENDING,
        ]);
        $returnedToAccounting = $this->receivableFor($maker, [
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_ACCOUNTING_MAKER,
        ]);

        $this->assertTrue($maker->can('update', $draft));
        $this->assertTrue($maker->can('update', $returnedToBranch));
        $this->assertFalse($maker->can('update', $accountingStage));
        $this->assertFalse($maker->can('update', $returnedToAccounting));
        $this->actingAs($maker)
            ->get(InsuranceReceivableResource::getUrl('edit', ['record' => $accountingStage]))
            ->assertForbidden();
        $document = $accountingStage->documents()->create([
            'claim_document_type_id' => ClaimDocumentType::query()->firstOrFail()->id,
            'file_path' => 'testing/document.pdf',
            'original_file_name' => 'document.pdf',
            'mime_type' => 'application/pdf',
            'uploaded_by' => $maker->id,
            'uploaded_at' => now(),
        ]);
        $this->assertTrue($maker->can('update', $document));
        $this->assertTrue($maker->can('delete', $document));
    }

    public function test_it_collectability_confirmation_does_not_change_collectability_and_writes_stage_log(): void
    {
        $this->seedDependencies();
        $itUser = $this->userWithRole('it_user', '000');
        $receivable = InsuranceReceivable::factory()->create([
            'collectability' => '1',
            'saving_account_for_loan_repayment' => '1000010000000691',
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING,
        ]);

        $result = app(ConfirmCollectabilityChangeCompletedAction::class)->handle($receivable, $itUser);

        $this->assertSame('1', $result->collectability);
        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION_PENDING, $result->workflow_status);
        $this->assertTrue($result->stageLogs()->where('event', 'collectability_change_confirmed')->exists());
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function receivableFor(User $user, array $attributes = []): InsuranceReceivable
    {
        $receivable = InsuranceReceivable::factory()->create([
            'branch_office_id' => $user->branch_office_id,
            'branch_code' => $user->branchOffice->branch_code,
            'created_by' => $user->id,
            'saving_account_for_loan_repayment' => '1000010000000691',
            ...$attributes,
        ]);

        $receivable->forceFill([
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED,
            'inquiry_completed_at' => now(),
        ])->saveQuietly();

        return $receivable->refresh();
    }

    private function userWithRole(string $role, string $branchCode): User
    {
        $branchOffice = BranchOffice::query()->where('branch_code', $branchCode)->firstOrFail();
        $user = User::factory()->create(['branch_office_id' => $branchOffice->id]);
        $user->assignRole($role);

        return $user;
    }

    private function loanInquiryData(array $overrides = []): array
    {
        return [
            'accountNumber' => '3010000000000001',
            'altNumber' => 'ALT-1',
            'branchCode' => '001',
            'cifNo' => 'CIF-1',
            'cifNoAlt' => 'CIFA-1',
            'customerName' => 'Customer',
            'collectability' => '1',
            'dpd' => 0,
            'productID' => 'P1',
            'productName' => 'Loan',
            'saForLoanRepayment' => '1000010000000691',
            'startPeriod' => '20250101',
            'endPeriod' => '20270101',
            'creditLimit' => '250000000.00',
            'loanOutStanding' => '230929000.00',
            'installmentAmount' => '1000.00',
            'nextDueDate' => '20260531',
            ...$overrides,
        ];
    }

    private function contractResponse(string $bakiDebet, string $accountNumber): array
    {
        return [
            'result' => [
                'AccountNumber' => $accountNumber,
                'AsOf' => '2026-06-30T00:00:00Z',
                'BakiDebet' => $bakiDebet,
            ],
            'loan' => [
                'AccountNumber' => $accountNumber,
                'Product' => '301 - Kredit Pegawai Aktif',
            ],
        ];
    }
}
