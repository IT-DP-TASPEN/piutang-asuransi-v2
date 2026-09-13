<?php

namespace Tests\Feature;

use App\Actions\InsuranceReceivable\ApproveInsuranceReceivableApprovalAction;
use App\Models\ApiIntegrationLog;
use App\Models\ApprovalRequest;
use App\Models\BranchOffice;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\ContractOutstanding\ContractOutstandingClient;
use App\Services\InsuranceReceivable\CalculateEarlyTerminationSplitTopUp;
use App\Services\InsuranceReceivable\ResolveLoanProductLsaTransactionType;
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

class ContractOutstandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'core_banking.base_url' => 'http://core.test',
            'core_banking.signature_secret' => 'secret-key',
            'services.contract_outstanding.base_url' => 'http://contract.test',
            'services.contract_outstanding.endpoint' => '/api/slik/inquiry',
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

    public function test_contract_client_posts_bearer_json_and_sanitizes_log(): void
    {
        Http::fake([
            'http://contract.test/api/slik/inquiry' => Http::response($this->contractResponse('103499793')),
        ]);

        $result = app(ContractOutstandingClient::class)->inquire('3000010000000113', '2026-06-30');

        $this->assertSame('103499793.00', (string) $result->bakiDebet->toScale(2));
        $this->assertSame('2026-06-30', $result->requestedAsOf);
        $this->assertSame('2026-06-30', $result->returnedAsOf);
        $this->assertSame('301 - Kredit Pegawai Aktif', $result->loanProduct);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://contract.test/api/slik/inquiry'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && ! $request->hasHeader('Signature')
            && $request->data() === [
                'account_number' => '3000010000000113',
                'as_of' => '2026-06-30',
            ]);

        $log = ApiIntegrationLog::query()->where('service_name', 'contract_outstanding')->sole();
        $this->assertTrue($log->is_success);
        $this->assertSame(['Accept' => 'application/json', 'Content-Type' => 'application/json'], $log->request_headers);
        $this->assertStringNotContainsString('test-token', json_encode($log->toArray()));
    }

    public function test_contract_client_accepts_wrapped_response_and_rejects_float_baki_debet(): void
    {
        $wrapped = [
            'data' => $this->contractResponse('1000'),
        ];
        Http::fake([
            'http://contract.test/api/slik/inquiry' => Http::sequence()
                ->push($wrapped)
                ->push($this->contractResponse(1000.50)),
        ]);

        $this->assertSame('1000.00', (string) app(ContractOutstandingClient::class)
            ->inquire('3000010000000113', '2026-06-30')
            ->bakiDebet
            ->toScale(2));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('BakiDebet as an integer or numeric string');

        app(ContractOutstandingClient::class)->inquire('3000010000000113', '2026-06-30');
    }

    public function test_contract_client_missing_config_fails_before_http(): void
    {
        config(['services.contract_outstanding.token' => null]);
        Http::fake();

        $this->expectException(ValidationException::class);

        try {
            app(ContractOutstandingClient::class)->inquire('3000010000000113', '2026-06-30');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_product_mapping_and_split_calculator(): void
    {
        $resolver = app(ResolveLoanProductLsaTransactionType::class);

        foreach ($resolver->mappings() as $code => $trxType) {
            $this->assertSame($trxType, $resolver->resolve("{$code} - Product")['trx_type']);
        }

        $this->assertNull($resolver->resolve('999 - Unknown'));

        $split = app(CalculateEarlyTerminationSplitTopUp::class)->handle('100.00', '93.00');

        $this->assertSame('7.00', (string) $split->spread);
        $this->assertSame('100.00', (string) $split->totalFundingAmount);
        $this->assertSame('7.00', (string) $split->lsaTopUpAmount);
        $this->assertSame('93.00', (string) $split->piutangTopUpAmount);
        $this->assertTrue($split->lsaTopUpAmount->plus($split->piutangTopUpAmount)->isEqualTo($split->totalFundingAmount));
    }

    public function test_accounting_validation_forms_receivable_from_contract_and_reuses_snapshot(): void
    {
        [$receivable, $approver] = $this->accountingValidationReceivable(['loan_outstanding' => '10000.00']);
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::sequence()
                ->push($this->loanResponse('10000.00'))
                ->push($this->loanResponse('10000.00')),
            'http://contract.test/api/slik/inquiry' => Http::response($this->contractResponse('9000')),
        ]);

        $result = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $approver);

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED, $result->workflow_status);
        $this->assertSame('10000.00', $result->loan_outstanding);
        $this->assertSame('9000.00', $result->receivable_amount);
        $this->assertSame('9000.00', $result->remaining_receivable_amount);
        $this->assertSame('9000.00', $result->contract_outstanding_amount);
        $this->assertSame('301', $result->contract_outstanding_product_code);
        $this->assertSame('LSA01', $result->contract_outstanding_trx_type);
    }

    public function test_accounting_validation_reuses_snapshot_but_revalidates_against_fresh_fincloud(): void
    {
        [$retry, $retryApprover] = $this->accountingValidationReceivable([
            'loan_outstanding' => '10000.00',
            'contract_outstanding_amount' => '9000.00',
            'contract_outstanding_requested_as_of' => '2026-06-30',
            'contract_outstanding_as_of' => '2026-06-30',
            'contract_outstanding_product_code' => '301',
            'contract_outstanding_trx_type' => 'LSA01',
        ]);
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::sequence()
                ->push($this->loanResponse('10000.00'))
                ->push($this->loanResponse('8000.00')),
            'http://contract.test/api/slik/inquiry' => Http::response(['should_not' => 'call']),
        ]);

        try {
            app(ApproveInsuranceReceivableApprovalAction::class)->handle($retry, $retryApprover);
            $this->fail('Inconsistent reused snapshot should block.');
        } catch (ValidationException) {
            $this->assertSame(ApprovalRequest::STATUS_SUBMITTED, ApprovalRequest::query()
                ->where('workflow_code', ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION)
                ->latest('id')
                ->firstOrFail()
                ->status);
        }

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'contract.test'));
    }

    public function test_accounting_approval_blocks_when_fresh_collectability_is_no_longer_five(): void
    {
        [$receivable, $approver] = $this->accountingValidationReceivable(['loan_outstanding' => '10000.00']);
        $loan = $this->loanResponse('10000.00');
        $loan['data']['collectability'] = '4';
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response($loan),
            'http://contract.test/api/slik/inquiry' => Http::response($this->contractResponse('9000')),
        ]);

        try {
            app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $approver);
            $this->fail('Fresh collectability 4 must block Accounting approval.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('actual: 4', $exception->errors()['collectability'][0]);
        }

        $this->assertSame(ApprovalRequest::STATUS_SUBMITTED, $receivable->approvalRequests()->sole()->status);
        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION, $receivable->refresh()->workflow_status);
    }

    public function test_accounting_approval_blocks_when_fresh_repayment_account_is_not_branch_oper(): void
    {
        [$receivable, $approver] = $this->accountingValidationReceivable(['loan_outstanding' => '10000.00']);
        $loan = $this->loanResponse('10000.00');
        $loan['data']['saForLoanRepayment'] = '002000OPER';
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response($loan),
            'http://contract.test/api/slik/inquiry' => Http::response($this->contractResponse('9000')),
        ]);

        try {
            app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $approver);
            $this->fail('Fresh wrong-branch OPER must block Accounting approval.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('001000OPER', $exception->errors()['saving_account_for_loan_repayment'][0]);
        }

        $this->assertSame(ApprovalRequest::STATUS_SUBMITTED, $receivable->approvalRequests()->sole()->status);
        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION, $receivable->refresh()->workflow_status);
    }

    private function accountingValidationReceivable(array $attributes = []): array
    {
        $approver = $this->userWithRole('accounting_approver', '000');
        $it = $this->userWithRole('it_user', '000');
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $receivable = InsuranceReceivable::factory()->create([
            'branch_office_id' => $branch->id,
            'branch_code' => $branch->branch_code,
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED,
            'loan_account_number' => '3000010000000113',
            'saving_account_for_loan_repayment' => '001000OPER',
            'collectability' => '5',
            'date_of_death' => '2026-06-30',
            ...$attributes,
        ]);

        app(ApprovalService::class)->submit(
            $receivable,
            ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION,
            $it,
        );

        return [$receivable->refresh(), $approver];
    }

    private function userWithRole(string $role, string $branchCode): User
    {
        $branch = BranchOffice::query()->where('branch_code', $branchCode)->firstOrFail();
        $user = User::factory()->create(['branch_office_id' => $branch->id]);
        $user->assignRole($role);

        return $user;
    }

    private function loanResponse(string $outstanding): array
    {
        return [
            'responseCode' => '00',
            'description' => 'SUCCESS',
            'data' => [
                'accountNumber' => '3000010000000113',
                'altNumber' => 'ALT-1',
                'branchCode' => '001',
                'collectability' => '5',
                'loanOutStanding' => $outstanding,
                'installmentAmount' => '1000.00',
                'nextDueDate' => '20260630',
                'saForLoanRepayment' => '001000OPER',
            ],
        ];
    }

    private function contractResponse(int|string|float $bakiDebet): array
    {
        return [
            'result' => [
                'AccountNumber' => '3000010000000113',
                'AsOf' => '2026-06-30T00:00:00Z',
                'BakiDebet' => $bakiDebet,
            ],
            'loan' => [
                'AccountNumber' => '3000010000000113',
                'AltNumber' => '0130102980',
                'Product' => '301 - Kredit Pegawai Aktif',
            ],
        ];
    }
}
