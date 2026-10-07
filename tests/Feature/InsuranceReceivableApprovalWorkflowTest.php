<?php

namespace Tests\Feature;

use App\Actions\InsuranceReceivable\ApproveInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\AutoSubmitInsuranceReceivableForInitialApprovalAction;
use App\Actions\InsuranceReceivable\ConfirmCollectabilityChangeCompletedAction;
use App\Actions\InsuranceReceivable\RejectInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\ReturnInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\SubmitInsuranceReceivableForApprovalAction;
use App\Jobs\RunKolekRpaJob;
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
use Illuminate\Support\Facades\Queue;
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

    public function test_initial_accounts_preserve_core_snapshot_and_create_full_approval_chain(): void
    {
        $maker = $this->userWithRole('branch_maker', '001');
        foreach (['0011234567', null, '001000OPER', '002000OPER'] as $account) {
            $result = app(AutoSubmitInsuranceReceivableForInitialApprovalAction::class)
                ->handle($this->receivableFor($maker, ['saving_account_for_loan_repayment' => $account]), $maker);

            $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED, $result->workflow_status);
            $this->assertSame($account, $result->saving_account_for_loan_repayment);
            $this->assertSame(InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED, $result->system_status);
            $this->assertSame([
                'branch_approver',
                'insurance_approver',
                'business_approver',
            ], $result->approvalRequests()->sole()->steps()->orderBy('step_order')->pluck('role_name')->all());
            $this->assertFalse($result->stageLogs()->where('event', 'oper_account_validation_failed')->exists());
        }
    }

    public function test_manual_initial_submission_accepts_agf_account(): void
    {
        $maker = $this->userWithRole('branch_maker', '001');
        $admin = $this->userWithRole('super_admin', '000');
        $result = app(SubmitInsuranceReceivableForApprovalAction::class)->handle(
            $this->receivableFor($maker, ['saving_account_for_loan_repayment' => '0011234567']),
            $admin,
        );

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED, $result->workflow_status);
        $this->assertSame('0011234567', $result->saving_account_for_loan_repayment);
    }

    public function test_only_current_initial_approver_can_act_and_it_waits_for_all_three(): void
    {
        Http::fake(['http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse('4'))]);
        $maker = $this->userWithRole('branch_maker', '001');
        $bm = $this->userWithRole('branch_approver', '001');
        $insurance = $this->userWithRole('insurance_approver', '000');
        $business = $this->userWithRole('business_approver', '000');
        $receivable = app(AutoSubmitInsuranceReceivableForInitialApprovalAction::class)
            ->handle($this->receivableFor($maker, ['saving_account_for_loan_repayment' => '0011234567']), $maker);

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
        $this->assertSame('001000OPER', $receivable->saving_account_for_loan_repayment);
        $this->assertTrue($receivable->stageLogs()->where('event', 'initial_approval_chain_completed')->exists());
    }

    public function test_return_at_each_initial_step_restarts_a_fresh_full_chain(): void
    {
        Http::fake(['http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse('4'))]);
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
        Http::fake(['http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse('4'))]);
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

    public function test_it_blocks_wrong_branch_oper_and_stays_at_it(): void
    {
        $it = $this->userWithRole('it_user', '000');
        $receivable = $this->itReceivable();
        Http::fake(['http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse('5', '002000OPER'))]);

        try {
            app(ConfirmCollectabilityChangeCompletedAction::class)->handle($receivable, $it);
            $this->fail('Wrong branch OPER must block IT confirmation.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('001000OPER', $exception->getMessage());
            $this->assertStringContainsString('002000OPER', $exception->getMessage());
        }

        $receivable->refresh();
        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING, $receivable->workflow_status);
        $this->assertSame(InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED, $receivable->system_status);
        $this->assertSame('002000OPER', $receivable->saving_account_for_loan_repayment);
        $this->assertStringContainsString('001000OPER', $receivable->last_error_message);
        $this->assertFalse($receivable->approvalRequests()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION)->exists());
        $log = $receivable->stageLogs()->where('event', 'it_changes_confirmation_blocked')->firstOrFail();
        $this->assertSame('001000OPER', $log->metadata['expected_oper_account']);
        $this->assertSame('002000OPER', $log->metadata['actual_repayment_account']);
        $this->assertTrue($log->metadata['oper_account_failed']);
    }

    public function test_it_reports_both_unmet_core_changes_and_can_retry(): void
    {
        $it = $this->userWithRole('it_user', '000');
        $receivable = $this->itReceivable(['saving_account_for_loan_repayment' => '0011234567']);
        Http::fake(['http://core.test/inquiry/detail/loan' => Http::sequence()
            ->push($this->loanResponse('4', '0011234567'))
            ->push($this->loanResponse('5', '001000OPER'))]);

        try {
            app(ConfirmCollectabilityChangeCompletedAction::class)->handle($receivable, $it);
            $this->fail('Both incorrect Core values must block confirmation.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('actual: 4', $exception->getMessage());
            $this->assertStringContainsString('001000OPER', $exception->getMessage());
            $this->assertStringContainsString('0011234567', $exception->getMessage());
        }

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING, $receivable->refresh()->workflow_status);
        $this->assertStringContainsString('actual: 4', $receivable->last_error_message);
        $this->assertStringContainsString('0011234567', $receivable->last_error_message);
        $log = $receivable->stageLogs()->where('event', 'it_changes_confirmation_blocked')->firstOrFail();
        $this->assertTrue($log->metadata['collectability_failed']);
        $this->assertTrue($log->metadata['oper_account_failed']);

        $result = app(ConfirmCollectabilityChangeCompletedAction::class)->handle($receivable, $it);
        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION, $result->workflow_status);
        $this->assertNull($result->last_error_message);
    }

    public function test_it_blocks_agf_account_even_when_collectability_is_five(): void
    {
        $it = $this->userWithRole('it_user', '000');
        $receivable = $this->itReceivable();
        Http::fake(['http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse('5', '0011234567'))]);

        try {
            app(ConfirmCollectabilityChangeCompletedAction::class)->handle($receivable, $it);
            $this->fail('AGF account must block IT confirmation.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('0011234567', $exception->getMessage());
        }

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING, $receivable->refresh()->workflow_status);
        $this->assertFalse($receivable->approvalRequests()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION)->exists());
    }

    public function test_it_valid_fresh_data_goes_directly_to_accounting_approver(): void
    {
        $it = $this->userWithRole('it_user', '000');
        $receivable = $this->itReceivable(['collectability' => '1']);
        Http::fake(['http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse('5', '001000oper'))]);

        $result = app(ConfirmCollectabilityChangeCompletedAction::class)->handle($receivable, $it);
        $request = $result->approvalRequests()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION)->sole();

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION, $result->workflow_status);
        $this->assertSame('5', $result->collectability);
        $this->assertSame('001000oper', $result->saving_account_for_loan_repayment);
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

    public function test_initial_submission_queues_oper_rpa_and_job_submits_branch_oper(): void
    {
        Queue::fake();
        $maker = $this->userWithRole('branch_maker', '001');
        $receivable = app(AutoSubmitInsuranceReceivableForInitialApprovalAction::class)
            ->handle($this->receivableFor($maker, ['saving_account_for_loan_repayment' => '0011234567']), $maker);

        Queue::assertPushed(RunKolekRpaJob::class, fn (RunKolekRpaJob $job): bool => $job->insuranceReceivableId === $receivable->id
            && $job->operation === RunKolekRpaJob::OPERATION_OPER_ACCOUNT);

        $this->fakeRepaymentFincloud($receivable, 'Active');
        $this->runRpa($receivable, RunKolekRpaJob::OPERATION_OPER_ACCOUNT);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://fincloud.test/admin/access/login'
            && $request['locationid'] === substr($receivable->loan_account_number, 3, 3)
            && $request['pwd'] === 'rpa-pass');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://fincloud.test/pinjaman/pendaftaranPenghapusanAutodebit/pembuatan/pinjaman'
            && $request->hasHeader('sessionid', 'session-1')
            && $request['id'] === $receivable->loan_account_number
            && $request['norektab_bayarangsuran'] === '001000OPER'
            && $request['tabbayar_namapemilik'] === 'OPER 001'
            && $request['tgl_pencairan'] === '2013-3-18'
            && $request['plafondlimit'] === '30000000'
            && $request['status_dokumen'] === 'Diajukan');
        $this->assertTrue($receivable->stageLogs()->where('event', 'oper_account_rpa_succeeded')->exists());
        $this->assertFalse(RunKolekRpaJob::canRetry($receivable->refresh(), RunKolekRpaJob::OPERATION_OPER_ACCOUNT));
    }

    public function test_oper_rpa_skips_when_already_oper_and_failure_enables_manual_retry(): void
    {
        $maker = $this->userWithRole('branch_maker', '001');
        $alreadyOper = $this->receivableFor($maker, ['workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED]);
        $agf = $this->receivableFor($maker, [
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
            'saving_account_for_loan_repayment' => '0011234567',
        ]);

        $this->fakeRepaymentFincloud($agf, 'Closed');
        $this->runRpa($alreadyOper, RunKolekRpaJob::OPERATION_OPER_ACCOUNT);
        Http::assertNothingSent();

        $this->runRpa($agf, RunKolekRpaJob::OPERATION_OPER_ACCOUNT);
        $agf->refresh();
        $this->assertSame('Saving account 001000OPER has status Closed.', $agf->last_error_message);
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/pembuatan/pinjaman'));
        $this->assertTrue($agf->stageLogs()->where('event', 'oper_account_rpa_failed')->exists());
        $this->assertTrue(RunKolekRpaJob::canRetry($agf, RunKolekRpaJob::OPERATION_OPER_ACCOUNT));
    }

    public function test_bm_approval_is_blocked_until_fresh_inquiry_shows_branch_oper(): void
    {
        Queue::fake();
        Http::fake(['http://core.test/inquiry/detail/loan' => Http::sequence()
            ->push($this->loanResponse('4', '0011234567'))
            ->push($this->loanResponse('4'))]);
        $maker = $this->userWithRole('branch_maker', '001');
        $bm = $this->userWithRole('branch_approver', '001');
        $receivable = app(AutoSubmitInsuranceReceivableForInitialApprovalAction::class)
            ->handle($this->receivableFor($maker, ['saving_account_for_loan_repayment' => '0011234567']), $maker);

        try {
            app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $bm);
            $this->fail('BM must not approve before the OPER change is live in Fincloud.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('branch OPER 001000OPER; actual: 0011234567', $exception->getMessage());
        }

        $request = $receivable->approvalRequests()->latest('id')->firstOrFail();
        $this->assertSame(ApprovalStep::STATUS_PENDING, $request->steps()->orderBy('step_order')->value('status'));
        $this->assertTrue($receivable->stageLogs()->where('event', 'branch_approval_oper_check_blocked')->exists());

        $approved = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable->refresh(), $bm);
        $this->assertSame(ApprovalStep::STATUS_APPROVED, $request->steps()->orderBy('step_order')->value('status'));
        $this->assertNull($approved->last_error_message);
    }

    public function test_completed_initial_chain_queues_collectability_rpa_for_kolek_five_manual(): void
    {
        Queue::fake();
        Http::fake(['http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse('4'))]);
        $maker = $this->userWithRole('branch_maker', '001');
        $receivable = app(AutoSubmitInsuranceReceivableForInitialApprovalAction::class)
            ->handle($this->receivableFor($maker), $maker);
        foreach (['branch_approver' => '001', 'insurance_approver' => '000', 'business_approver' => '000'] as $role => $branch) {
            $receivable = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $this->userWithRole($role, $branch));
        }

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING, $receivable->workflow_status);
        Queue::assertPushed(RunKolekRpaJob::class, fn (RunKolekRpaJob $job): bool => $job->operation === RunKolekRpaJob::OPERATION_COLLECTABILITY);

        $this->configureRpa();
        $inquiry = fn (int $kolekBi, string $updateBi): array => ['status' => 'ok', 'data' => ['result' => [
            'norekening' => $receivable->loan_account_number,
            'namanasabah' => 'Ratna Juwita',
            'nopk' => 'PL001000073837',
            'appdate' => ['date' => '2026-08-27 00:00:00.000000'],
            'datarekening' => [
                'kolekbi' => $kolekBi, 'kolekbpr' => 5, 'updatekolekbi' => $updateBi, 'updatekolekbpr' => 'Manual',
                'dpd' => 4423, 'totalassetvalue' => 0, 'totalcollateralvalue' => 0,
            ],
        ]]];
        Http::fake([
            'https://fincloud.test/admin/access/login' => Http::response(['status' => 'ok', 'data' => ['result' => ['sessionid' => 'session-1']]]),
            'https://fincloud.test/pinjaman/updateManualKolek/pembuatan/cari*' => Http::sequence()
                ->push($inquiry(2, 'Automatic'))
                ->push($inquiry(2, 'Automatic'))
                ->push($inquiry(5, 'Manual')),
            'https://fincloud.test/pinjaman/updateManualKolek/pembuatan/pinjaman' => Http::sequence()
                ->push(['status' => 'error', 'error' => ['system' => 'Fincloud operation failed']])
                ->push(['status' => 'ok']),
        ]);

        $this->runRpa($receivable, RunKolekRpaJob::OPERATION_COLLECTABILITY);
        $this->assertSame('Fincloud rejected /pinjaman/updateManualKolek/pembuatan/pinjaman: Fincloud operation failed.', $receivable->refresh()->last_error_message);
        $this->assertTrue(RunKolekRpaJob::canRetry($receivable, RunKolekRpaJob::OPERATION_COLLECTABILITY));

        $this->runRpa($receivable, RunKolekRpaJob::OPERATION_COLLECTABILITY);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://fincloud.test/pinjaman/updateManualKolek/pembuatan/pinjaman'
            && $request['norekening'] === $receivable->loan_account_number
            && $request['tgl_transaksi'] === '2026-8-27'
            && $request['nilai_kolekbilama'] === '2'
            && $request['nilai_kolekbi'] === '5'
            && $request['nilai_kolekbpr'] === '5'
            && $request['jenisperubahan_kolekbi'] === 'Manual');
        $this->assertFalse(RunKolekRpaJob::canRetry($receivable->refresh(), RunKolekRpaJob::OPERATION_COLLECTABILITY));
        $this->assertNull($receivable->last_error_message);

        $this->runRpa($receivable, RunKolekRpaJob::OPERATION_COLLECTABILITY);
        $this->assertSame('SKIPPED', $receivable->stageLogs()->where('event', 'collectability_rpa_succeeded')->latest('id')->firstOrFail()->metadata['status']);
    }

    private function configureRpa(): void
    {
        config(['services.fincloud_web' => [
            'base_url' => 'https://fincloud.test',
            'username' => 'rpa-user',
            'password' => 'rpa-pass',
            'role_id' => 'RPA',
            'verify_ssl' => true,
            'timeout' => 15,
        ]]);
    }

    private function fakeRepaymentFincloud(InsuranceReceivable $receivable, string $savingStatus): void
    {
        $this->configureRpa();
        Http::fake([
            'https://fincloud.test/admin/access/login' => Http::response(['status' => 'ok', 'data' => ['result' => ['sessionid' => 'session-1']]]),
            'https://fincloud.test/pinjaman/pendaftaranPenghapusanAutodebit/pembuatan/cari*' => Http::response(['status' => 'ok', 'data' => ['result' => [
                'id' => $receivable->loan_account_number,
                'namanasabah' => 'Ratna Juwita',
                'plafondlimit' => 30000000,
                'tgl_pencairan' => ['date' => '2013-03-18 00:00:00.000000'],
                'pejabatkredit' => null,
            ]]]),
            'http://core.test/saving/inq/balance*' => Http::response(['responseCode' => '00', 'data' => [
                'accountNumber' => '001000OPER',
                'customerName' => 'OPER 001',
                'documentStatus' => $savingStatus,
                'currency' => 'IDR',
            ]]),
            'https://fincloud.test/pinjaman/pendaftaranPenghapusanAutodebit/pembuatan/pinjaman' => Http::response(['status' => 'ok']),
        ]);
    }

    private function runRpa(InsuranceReceivable $receivable, string $operation): void
    {
        app()->call([new RunKolekRpaJob($receivable->id, $operation), 'handle']);
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
