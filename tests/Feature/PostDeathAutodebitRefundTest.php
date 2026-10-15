<?php

namespace Tests\Feature;

use App\Actions\InsuranceReceivable\ApproveInsuranceReceivableApprovalAction;
use App\Models\ApprovalRequest;
use App\Models\BranchOffice;
use App\Models\GlToGlTransaction;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PostDeathAutodebitRefundTest extends TestCase
{
    use RefreshDatabase;

    private const GL_URL = 'http://core.test/trx/transfer/gl-to-gl';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'core_banking.base_url' => 'http://core.test',
            'core_banking.signature_secret' => 'secret-key',
            'services.contract_outstanding.base_url' => 'http://contract.test',
            'services.contract_outstanding.token' => 'test-token',
            'services.contract_outstanding.retry_times' => 0,
        ]);
        Carbon::setTestNow('2026-06-30 10:20:30');

        $this->seed([
            BranchOfficeSeeder::class,
            InsuranceCompanySeeder::class,
            ClaimStatusSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_refunds_each_payment_on_or_after_death_except_own_oper_repayment(): void
    {
        [$receivable, $approver] = $this->accountingValidationReceivable();
        $this->fakeCore(Http::response(['responseCode' => '00', 'description' => 'SUCCESS', 'data' => []]), [
            $this->payment('2026-06-01', '1500.00', 'J-BEFORE'),
            $this->payment('2026-06-14', '1500.00', 'J-ON-DEATH'),
            $this->payment('2026-06-20', '706.04', 'J-SPLIT-1'),
            $this->payment('2026-06-20', '793.96', 'J-SPLIT-2'),
            $this->payment('2026-06-30', '1500.00', 'J-OWN'),
        ]);

        $result = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $approver);

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED, $result->workflow_status);
        $this->assertEqualsCanonicalizing(
            ["ir:{$receivable->id}:post-death-refund:J-ON-DEATH", "ir:{$receivable->id}:post-death-refund:J-SPLIT-1", "ir:{$receivable->id}:post-death-refund:J-SPLIT-2"],
            GlToGlTransaction::query()->where('purpose', GlToGlTransaction::PURPOSE_POST_DEATH_AUTODEBIT_REFUND)->where('status', GlToGlTransaction::STATUS_SUCCESS)->pluck('idempotency_key')->all(),
        );
        Http::assertSent(fn (Request $request): bool => $request->url() === self::GL_URL
            && $request['amount'] === '706.04'
            && $request['trxType'] === 'LSA01'
            && $request['destAccount'] === '0010000000777'
            && $request['type'] === 'G2'
            && str_starts_with($request['referenceNumber'], 'MDRFD')
            && $request['description'] === trim('Refund Angsuran MD 3010000000000001 '.$receivable->customer_name));
    }

    public function test_rejected_refund_blocks_approval_and_retries_with_new_reference(): void
    {
        [$receivable, $approver] = $this->accountingValidationReceivable();
        $this->fakeCore(Http::sequence()
            ->push(['responseCode' => '51', 'description' => 'REJECTED', 'data' => []])
            ->push(['responseCode' => '00', 'description' => 'SUCCESS', 'data' => []]), [
                $this->payment('2026-06-20', '1500.00', 'J-AUTODEBIT'),
            ]);

        try {
            app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $approver);
            $this->fail('Rejected refund must block Accounting Validation approval.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('post_death_refund', $exception->errors());
        }

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION, $receivable->refresh()->workflow_status);

        $result = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $approver);
        $attempts = GlToGlTransaction::query()->where('purpose', GlToGlTransaction::PURPOSE_POST_DEATH_AUTODEBIT_REFUND)->orderBy('attempt_no')->get();

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED, $result->workflow_status);
        $this->assertSame([GlToGlTransaction::STATUS_FAILED, GlToGlTransaction::STATUS_SUCCESS], $attempts->pluck('status')->all());
        $this->assertSame(["MDRFD{$receivable->id}-1001", "MDRFD{$receivable->id}-2002"], $attempts->pluck('reference_number')->all());
    }

    public function test_reversal_after_death_blocks_without_posting(): void
    {
        [$receivable, $approver] = $this->accountingValidationReceivable();
        $this->fakeCore(Http::response(['responseCode' => '00', 'data' => []]), [
            $this->payment('2026-06-20', '1500.00', 'J-AUTODEBIT'),
            $this->payment('2026-06-21', '-1500.00', 'J-REVERSAL'),
        ]);

        try {
            app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $approver);
            $this->fail('Reversal must block the refund.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('reversal', $exception->errors()['post_death_refund'][0]);
        }

        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::GL_URL);
    }

    private function fakeCore(mixed $glResponse, array $history): void
    {
        $loan = [
            'responseCode' => '00',
            'description' => 'SUCCESS',
            'data' => [
                'accountNumber' => '3010000000000001',
                'altNumber' => 'ALT-1',
                'branchCode' => '001',
                'collectability' => '5',
                'dpd' => 0,
                'productID' => '301',
                'productName' => 'Kredit Pegawai Aktif',
                'saForLoanRepayment' => '001000OPER',
                'loanOutStanding' => '10000.00',
                'installmentAmount' => '1500.00',
                'nextDueDate' => '20260715',
            ],
        ];
        $afterRepayment = ['data' => ['loanOutStanding' => '8500.00'] + $loan['data']] + $loan;

        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::sequence()
                ->push($loan)
                ->whenEmpty(Http::response($afterRepayment)),
            'http://core.test/saving/inq/balance*' => Http::response([
                'responseCode' => '00',
                'data' => ['accountNumber' => '001000OPER', 'documentStatus' => 'Active', 'availableBalance' => '2000.00', 'ledgerBalance' => '2000.00'],
            ]),
            'http://core.test/loan/repayment/' => Http::response(['responseCode' => '00', 'description' => 'SUCCESS', 'data' => ['journalId' => 'J-OWN']]),
            'http://contract.test/api/v1/loans/*/contractual*' => Http::response(
                '{"requested_account":"3010000000000001","primary_account":"3010000000000001","as_of":"2026-06-30","contract_rate":12.50,"contractual_outstanding":8500,"position_source":"DWH","repayment_history":['.implode(',', $history).']}',
            ),
            self::GL_URL => $glResponse,
        ]);
    }

    private function payment(string $date, string $total, string $journal): string
    {
        return '{"payment_date":"'.$date.'","principal":'.$total.',"interest":0.00,"penalty":0.00,"early_termination_penalty":0.00,"dwp":0.00,"total_payment":'.$total.',"journal_number":"'.$journal.'"}';
    }

    private function accountingValidationReceivable(): array
    {
        $approver = $this->userWithRole('accounting_approver', '000');
        $it = $this->userWithRole('it_user', '000');
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $receivable = InsuranceReceivable::factory()->create([
            'branch_office_id' => $branch->id,
            'branch_code' => $branch->branch_code,
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED,
            'loan_account_number' => '3010000000000001',
            'saving_account_for_loan_repayment' => '001000OPER',
            'original_saving_account_for_loan_repayment' => '0010000000777',
            'collectability' => '5',
            'loan_outstanding' => '10000.00',
            'date_of_death' => '2026-06-14',
        ]);

        app(ApprovalService::class)->submit($receivable, ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION, $it);

        return [$receivable, $approver];
    }

    private function userWithRole(string $role, string $branchCode): User
    {
        $user = User::factory()->create(['branch_office_id' => BranchOffice::query()->where('branch_code', $branchCode)->value('id')]);
        $user->assignRole($role);

        return $user;
    }
}
