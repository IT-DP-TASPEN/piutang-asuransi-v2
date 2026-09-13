<?php

namespace Tests\Feature;

use App\Actions\InsuranceReceivable\ApproveInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\AutoSubmitInsuranceReceivableForInitialApprovalAction;
use App\Actions\InsuranceReceivable\ConfirmCollectabilityChangeCompletedAction;
use App\Actions\InsuranceReceivable\RejectInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\ReturnInsuranceReceivableApprovalAction;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\BranchOffice;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\InsuranceReceivable\OperRepaymentAccount;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InsuranceReceivableApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'core_banking.base_url' => 'http://core.test',
            'core_banking.signature_secret' => 'secret-key',
        ]);
        $this->seed([
            BranchOfficeSeeder::class,
            InsuranceCompanySeeder::class,
            ClaimStatusSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    public function test_oper_account_is_derived_from_any_valid_branch_code_and_normalized(): void
    {
        $service = app(OperRepaymentAccount::class);

        foreach ([
            ['000', '000000OPER'],
            ['001', '001000OPER'],
            ['123', '123000OPER'],
        ] as [$branchCode, $account]) {
            $receivable = InsuranceReceivable::factory()->make([
                'branch_code' => $branchCode,
                'saving_account_for_loan_repayment' => '  '.strtolower($account).'  ',
            ]);

            $this->assertSame($account, $service->expected($receivable));
            $this->assertTrue($service->matches($receivable));
        }

        foreach (['001999OPER', '002000OPER', '1000010000000691', ''] as $account) {
            $receivable = InsuranceReceivable::factory()->make([
                'branch_code' => '001',
                'saving_account_for_loan_repayment' => $account,
            ]);

            $this->assertFalse($service->matches($receivable));
        }

        $this->expectException(ValidationException::class);
        $service->expected(InsuranceReceivable::factory()->make(['branch_code' => null]));
    }

    public function test_valid_oper_creates_full_ordered_initial_approval_chain(): void
    {
        $maker = $this->userWithRole('branch_maker', '001');
        $result = app(AutoSubmitInsuranceReceivableForInitialApprovalAction::class)
            ->handle($this->receivableFor($maker), $maker);
        $request = $result->approvalRequests()->sole();

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED, $result->workflow_status);
        $this->assertSame([
            'branch_approver',
            'insurance_approver',
            'business_approver',
        ], $request->steps()->orderBy('step_order')->pluck('role_name')->all());
    }

    public function test_invalid_oper_returns_without_approval_and_can_retry(): void
    {
        $maker = $this->userWithRole('branch_maker', '001');
        $receivable = $this->receivableFor($maker, ['saving_account_for_loan_repayment' => '002000OPER']);

        $returned = app(AutoSubmitInsuranceReceivableForInitialApprovalAction::class)->handle($receivable, $maker);

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER, $returned->workflow_status);
        $this->assertSame(InsuranceReceivable::SYSTEM_STATUS_REINQUIRY_REQUIRED, $returned->system_status);
        $this->assertStringContainsString('001000OPER', $returned->last_error_message);
        $this->assertStringContainsString('002000OPER', $returned->last_error_message);
        $this->assertFalse($returned->approvalRequests()->exists());

        $returned->forceFill([
            'saving_account_for_loan_repayment' => '001000OPER',
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED,
        ])->saveQuietly();
        $resubmitted = app(AutoSubmitInsuranceReceivableForInitialApprovalAction::class)->handle($returned, $maker);

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED, $resubmitted->workflow_status);
        $this->assertCount(3, $resubmitted->approvalRequests()->sole()->steps);
    }

    public function test_only_current_initial_approver_can_act_and_it_waits_for_all_three(): void
    {
        $maker = $this->userWithRole('branch_maker', '001');
        $bm = $this->userWithRole('branch_approver', '001');
        $insurance = $this->userWithRole('insurance_approver', '000');
        $business = $this->userWithRole('business_approver', '000');
        $receivable = app(AutoSubmitInsuranceReceivableForInitialApprovalAction::class)
            ->handle($this->receivableFor($maker), $maker);

        try {
            app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $insurance);
            $this->fail('Manager Asuransi must not act before BM.');
        } catch (ValidationException) {
        }

        $receivable = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable->refresh(), $bm);
        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED, $receivable->workflow_status);
        $receivable = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $insurance);
        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED, $receivable->workflow_status);
        $receivable = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $business);

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING, $receivable->workflow_status);
        $this->assertTrue($receivable->stageLogs()->where('event', 'initial_approval_chain_completed')->exists());
    }

    public function test_return_at_each_initial_step_restarts_a_fresh_full_chain(): void
    {
        $maker = $this->userWithRole('branch_maker', '001');
        $approvers = [
            $this->userWithRole('branch_approver', '001'),
            $this->userWithRole('insurance_approver', '000'),
            $this->userWithRole('business_approver', '000'),
        ];

        foreach ($approvers as $index => $currentApprover) {
            $receivable = app(AutoSubmitInsuranceReceivableForInitialApprovalAction::class)
                ->handle($this->receivableFor($maker), $maker);

            for ($step = 0; $step < $index; $step++) {
                $receivable = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $approvers[$step]);
            }

            $returned = app(ReturnInsuranceReceivableApprovalAction::class)->handle($receivable, $currentApprover, 'revise');
            $oldRequest = $returned->approvalRequests()->latest('id')->firstOrFail();
            $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER, $returned->workflow_status);

            $returned->forceFill([
                'system_status' => InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED,
                'saving_account_for_loan_repayment' => '001000OPER',
            ])->saveQuietly();
            $resubmitted = app(AutoSubmitInsuranceReceivableForInitialApprovalAction::class)->handle($returned, $maker);
            $newRequest = $resubmitted->approvalRequests()->latest('id')->firstOrFail();

            $this->assertNotSame($oldRequest->id, $newRequest->id);
            $this->assertSame([
                ApprovalStep::STATUS_PENDING,
                ApprovalStep::STATUS_PENDING,
                ApprovalStep::STATUS_PENDING,
            ], $newRequest->steps()->orderBy('step_order')->pluck('status')->all());
        }
    }

    public function test_reject_at_any_initial_step_is_terminal(): void
    {
        $maker = $this->userWithRole('branch_maker', '001');
        $approvers = [
            $this->userWithRole('branch_approver', '001'),
            $this->userWithRole('insurance_approver', '000'),
            $this->userWithRole('business_approver', '000'),
        ];

        foreach ($approvers as $index => $currentApprover) {
            $receivable = app(AutoSubmitInsuranceReceivableForInitialApprovalAction::class)
                ->handle($this->receivableFor($maker), $maker);
            for ($step = 0; $step < $index; $step++) {
                $receivable = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $approvers[$step]);
            }

            $rejected = app(RejectInsuranceReceivableApprovalAction::class)->handle($receivable, $currentApprover, 'terminal');
            $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_REJECTED, $rejected->workflow_status);
        }
    }

    public function test_it_uses_fresh_collectability_and_stays_at_it_when_not_five(): void
    {
        $it = $this->userWithRole('it_user', '000');
        $receivable = $this->itReceivable(['collectability' => '5']);
        Http::fake(['http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse('4'))]);

        try {
            app(ConfirmCollectabilityChangeCompletedAction::class)->handle($receivable, $it);
            $this->fail('Fresh collectability 4 must block confirmation.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('actual: 4', $exception->errors()['collectability'][0]);
        }

        $receivable->refresh();
        $this->assertSame('4', $receivable->collectability);
        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING, $receivable->workflow_status);
        $this->assertFalse($receivable->approvalRequests()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION)->exists());
    }

    public function test_it_returns_fresh_wrong_oper_to_branch_maker(): void
    {
        $it = $this->userWithRole('it_user', '000');
        $receivable = $this->itReceivable();
        Http::fake(['http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse('5', '002000OPER'))]);

        $result = app(ConfirmCollectabilityChangeCompletedAction::class)->handle($receivable, $it);

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER, $result->workflow_status);
        $this->assertSame(InsuranceReceivable::SYSTEM_STATUS_REINQUIRY_REQUIRED, $result->system_status);
        $this->assertFalse($result->approvalRequests()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION)->exists());
    }

    public function test_it_valid_fresh_data_goes_directly_to_accounting_approver(): void
    {
        $it = $this->userWithRole('it_user', '000');
        $receivable = $this->itReceivable(['collectability' => '1']);
        Http::fake(['http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse('5'))]);

        $result = app(ConfirmCollectabilityChangeCompletedAction::class)->handle($receivable, $it);
        $request = $result->approvalRequests()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION)->sole();

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION, $result->workflow_status);
        $this->assertSame('5', $result->collectability);
        $this->assertSame(['accounting_approver'], $request->steps()->pluck('role_name')->all());
        $this->assertSame($it->id, $request->submitted_by);
        $this->assertDatabaseMissing('permissions', [
            'name' => 'SubmitAccountingValidation:InsuranceReceivable',
        ]);
    }

    public function test_accounting_return_goes_to_branch_maker_and_reject_is_terminal(): void
    {
        $approver = $this->userWithRole('accounting_approver', '000');

        $accountingReceivable = $this->accountingReceivable();
        $accountingReceivable->forceFill([
            'contract_outstanding_amount' => '93.00',
            'contract_outstanding_requested_as_of' => '2026-06-30',
            'contract_outstanding_as_of' => '2026-06-30',
            'contract_outstanding_product_code' => '301',
            'contract_outstanding_trx_type' => 'LSA01',
            'contract_outstanding_api_log_id' => null,
        ])->save();

        $returned = app(ReturnInsuranceReceivableApprovalAction::class)
            ->handle($accountingReceivable, $approver, 'revise');
        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER, $returned->workflow_status);
        $this->assertNull($returned->contract_outstanding_amount);
        $this->assertNull($returned->contract_outstanding_requested_as_of);
        $this->assertNull($returned->contract_outstanding_as_of);
        $this->assertNull($returned->contract_outstanding_product_code);
        $this->assertNull($returned->contract_outstanding_trx_type);
        $this->assertNull($returned->contract_outstanding_api_log_id);
        $returned->forceFill(['system_status' => InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED])->saveQuietly();
        $resubmitted = app(AutoSubmitInsuranceReceivableForInitialApprovalAction::class)
            ->handle($returned, $returned->creator);
        $this->assertSame([
            'branch_approver',
            'insurance_approver',
            'business_approver',
        ], $resubmitted->approvalRequests()->latest('id')->firstOrFail()->steps()->orderBy('step_order')->pluck('role_name')->all());

        $rejected = app(RejectInsuranceReceivableApprovalAction::class)
            ->handle($this->accountingReceivable(), $approver, 'reject');
        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_REJECTED, $rejected->workflow_status);
    }

    private function receivableFor(User $user, array $attributes = []): InsuranceReceivable
    {
        return InsuranceReceivable::factory()->create([
            'branch_office_id' => $user->branch_office_id,
            'branch_code' => $user->branchOffice->branch_code,
            'created_by' => $user->id,
            'saving_account_for_loan_repayment' => '001000OPER',
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED,
            'inquiry_completed_at' => now(),
            ...$attributes,
        ]);
    }

    private function itReceivable(array $attributes = []): InsuranceReceivable
    {
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();

        return InsuranceReceivable::factory()->create([
            'branch_office_id' => $branch->id,
            'branch_code' => '001',
            'saving_account_for_loan_repayment' => '001000OPER',
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED,
            ...$attributes,
        ]);
    }

    private function accountingReceivable(): InsuranceReceivable
    {
        $maker = $this->userWithRole('branch_maker', '001');
        $receivable = $this->itReceivable([
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
            'collectability' => '5',
            'created_by' => $maker->id,
        ]);
        app(ApprovalService::class)->submit(
            $receivable,
            ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION,
            $this->userWithRole('it_user', '000'),
        );

        return $receivable->refresh();
    }

    private function userWithRole(string $role, string $branchCode): User
    {
        $branch = BranchOffice::query()->where('branch_code', $branchCode)->firstOrFail();
        $user = User::factory()->create(['branch_office_id' => $branch->id]);
        $user->assignRole($role);

        return $user;
    }

    private function loanResponse(string $collectability, string $account = '001000OPER'): array
    {
        return [
            'responseCode' => '00',
            'description' => 'SUCCESS',
            'data' => [
                'accountNumber' => '3010000000000001',
                'altNumber' => 'ALT-1',
                'branchCode' => '001',
                'collectability' => $collectability,
                'saForLoanRepayment' => $account,
                'loanOutStanding' => '10000.00',
                'installmentAmount' => '1000.00',
                'nextDueDate' => '20270101',
            ],
        ];
    }
}
