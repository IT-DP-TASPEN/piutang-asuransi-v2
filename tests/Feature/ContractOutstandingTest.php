<?php

namespace Tests\Feature;

use App\Actions\InsuranceReceivable\ApproveInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\PrepareAccountingValidationContractOutstandingAction;
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

    public function test_contract_client_gets_bearer_json_and_sanitizes_log(): void
    {
        Http::fake([
            'http://contract.test/api/v1/loans/*/contractual*' => Http::response($this->contractResponse(103499793)),
        ]);

        $result = app(ContractOutstandingClient::class)->inquire('3000010000000113', '2026-06-30');

        $this->assertSame('103499793.00', (string) $result->contractualOutstanding);
        $this->assertSame('3000010000000113', $result->primaryAccount);
        $this->assertSame('DWH', $result->positionSource);
        $this->assertSame('2026-06-30', $result->requestedAsOf);
        $this->assertSame('2026-06-30', $result->returnedAsOf);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://contract.test/api/v1/loans/3000010000000113/contractual?as_of=2026-06-30'
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('Accept', 'application/json')
            && ! $request->hasHeader('Content-Type')
            && $request->data() === ['as_of' => '2026-06-30']
            && $request->body() === '');

        $log = ApiIntegrationLog::query()->where('service_name', 'contract_outstanding')->sole();
        $this->assertTrue($log->is_success);
        $this->assertSame('GET', $log->method);
        $this->assertSame('/api/v1/loans/3000010000000113/contractual', $log->endpoint);
        $this->assertSame(['Accept' => 'application/json'], $log->request_headers);
        $this->assertSame(['account' => '3000010000000113', 'as_of' => '2026-06-30'], $log->request_body);
        $this->assertStringNotContainsString('test-token', json_encode($log->toArray()));
    }

    public function test_contract_client_preserves_numeric_json_tokens_as_exact_decimals(): void
    {
        $sequence = Http::sequence();
        foreach (['0.00', '93.00', '93456789.12', '123456789012345.67'] as $amount) {
            $sequence->push('{"requested_account":"3000010000000113","primary_account":"3000010000000113","as_of":"2026-06-30","contract_rate":12.50,"contractual_outstanding":'.$amount.',"position_source":"DWH","repayment_history":[]}', 200, ['Content-Type' => 'application/json']);
        }
        Http::fake(['http://contract.test/api/v1/loans/*/contractual*' => $sequence]);

        foreach (['0.00', '93.00', '93456789.12', '123456789012345.67'] as $amount) {
            $this->assertSame($amount, (string) app(ContractOutstandingClient::class)
                ->inquire('3000010000000113', '2026-06-30')->contractualOutstanding);
        }
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

    public function test_alternate_account_is_encoded_and_primary_is_returned(): void
    {
        $alternate = 'ALT/1 ?';
        Http::fake([
            'http://contract.test/api/v1/loans/*/contractual*' => Http::response($this->contractResponse('93.00', $alternate)),
        ]);

        $result = app(ContractOutstandingClient::class)->inquire($alternate, '2026-06-30');

        $this->assertSame($alternate, $result->requestedAccount);
        $this->assertSame('3000010000000113', $result->primaryAccount);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://contract.test/api/v1/loans/ALT%2F1%20%3F/contractual?as_of=2026-06-30');
    }

    public function test_client_rejects_wrong_account_date_and_malformed_success(): void
    {
        $sequence = Http::sequence()
            ->push($this->contractResponse('93.00', 'another'))
            ->push($this->contractResponse('93.00', asOf: '2026-06-29'))
            ->push('{"requested_account":', 200);
        Http::fake(['http://contract.test/api/v1/loans/*/contractual*' => $sequence]);

        foreach (['requested account', 'as_of', 'Malformed JSON'] as $message) {
            try {
                app(ContractOutstandingClient::class)->inquire('3000010000000113', '2026-06-30');
                $this->fail('Invalid TRS response must block.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }

        $this->assertSame(0, ApiIntegrationLog::query()->where('service_name', 'contract_outstanding')->where('is_success', true)->count());
    }

    public function test_client_rejects_noncanonical_request_date_before_http(): void
    {
        Http::fake();

        $this->expectException(ValidationException::class);
        try {
            app(ContractOutstandingClient::class)->inquire('3000010000000113', '2026-6-30');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_client_keeps_full_repayment_history_without_changing_contractual_balance(): void
    {
        $body = str_replace('"repayment_history":[]', '"repayment_history":[{"payment_date":"2026-07-01","principal":1000000.00,"interest":120000.00,"penalty":0.00,"early_termination_penalty":0.00,"dwp":0.00,"total_payment":1120000.00,"journal_number":"J-12345"}]', $this->contractResponse('93.00'));
        Http::fake(['http://contract.test/api/v1/loans/*/contractual*' => Http::response($body)]);

        $result = app(ContractOutstandingClient::class)->inquire('3000010000000113', '2026-06-30');

        $this->assertSame('93.00', (string) $result->contractualOutstanding);
        $this->assertSame('1000000.00', $result->repaymentHistory[0]['principal']);
        $this->assertSame($body, ApiIntegrationLog::query()->where('service_name', 'contract_outstanding')->sole()->response_body['raw']);
    }

    public function test_client_maps_semantic_errors_without_retrying(): void
    {
        config(['services.contract_outstanding.retry_times' => 2]);
        $errors = [
            400 => ['bad_request', 'rejected'],
            401 => ['unauthorized', 'authentication'],
            404 => ['not_found', 'not found'],
            409 => ['ambiguous_account', 'multiple loans'],
            422 => ['unsupported_calculation', 'cannot calculate'],
        ];
        $sequence = Http::sequence();
        foreach ($errors as $status => [$code]) {
            $sequence->push(['error' => $code], $status);
        }
        Http::fake(['http://contract.test/api/v1/loans/*/contractual*' => $sequence]);

        foreach ($errors as [$code, $message]) {
            try {
                app(ContractOutstandingClient::class)->inquire('3000010000000113', '2026-06-30');
                $this->fail("TRS {$code} must block.");
            } catch (ValidationException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }

        Http::assertSentCount(5);
        $this->assertSame(array_column($errors, 0), ApiIntegrationLog::query()->where('service_name', 'contract_outstanding')->pluck('response_code')->all());
    }

    public function test_service_unavailable_retries_once_and_remains_an_error(): void
    {
        config(['services.contract_outstanding.retry_times' => 1]);
        Http::fake([
            'http://contract.test/api/v1/loans/*/contractual*' => Http::sequence()
                ->push(['error' => 'service_unavailable'], 503)
                ->push(['error' => 'service_unavailable'], 503),
        ]);

        try {
            app(ContractOutstandingClient::class)->inquire('3000010000000113', '2026-06-30');
            $this->fail('Persistent TRS 503 must block.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('temporarily unavailable', $exception->getMessage());
        }

        Http::assertSentCount(2);
        $log = ApiIntegrationLog::query()->where('service_name', 'contract_outstanding')->sole();
        $this->assertSame(503, $log->response_status);
        $this->assertSame('service_unavailable', $log->response_code);
    }

    public function test_internal_error_has_a_distinct_application_error(): void
    {
        Http::fake(['http://contract.test/api/v1/loans/*/contractual*' => Http::response(['error' => 'internal_error'], 500)]);

        try {
            app(ContractOutstandingClient::class)->inquire('3000010000000113', '2026-06-30');
            $this->fail('TRS 500 must block.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('failed internally', $exception->getMessage());
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
        [$receivable, $approver] = $this->accountingValidationReceivable(['loan_outstanding' => '100000000.00']);
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::sequence()
                ->push($this->loanResponse('100000000.00'))
                ->push($this->loanResponse('100000000.00')),
            'http://contract.test/api/v1/loans/*/contractual*' => Http::response($this->contractResponse('93000000.00')),
        ]);

        $result = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $approver);

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED, $result->workflow_status);
        $this->assertSame('100000000.00', $result->loan_outstanding);
        $this->assertSame('93000000.00', $result->receivable_amount);
        $this->assertSame('93000000.00', $result->remaining_receivable_amount);
        $this->assertSame('93000000.00', $result->contract_outstanding_amount);
        $this->assertSame('301', $result->product_id);
        $this->assertSame('301', $result->contract_outstanding_product_code);
        $this->assertSame('LSA01', $result->contract_outstanding_trx_type);
        $this->assertSame('DWH', $result->stageLogs()->where('event', 'contract_outstanding_snapshot_stored')->sole()->metadata['position_source']);
        $split = app(CalculateEarlyTerminationSplitTopUp::class)->handle($result->loan_outstanding, $result->contract_outstanding_amount);
        $this->assertSame('7000000.00', (string) $split->lsaTopUpAmount);
        $this->assertSame('93000000.00', (string) $split->piutangTopUpAmount);
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
            'http://contract.test/api/v1/loans/*/contractual*' => Http::response(['should_not' => 'call']),
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

    public function test_accounting_blocks_conflicting_trs_primary_account(): void
    {
        [$receivable, $approver] = $this->accountingValidationReceivable();
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse('100.00')),
            'http://contract.test/api/v1/loans/*/contractual*' => Http::response($this->contractResponse('93.00', primary: 'another-primary')),
        ]);

        try {
            app(PrepareAccountingValidationContractOutstandingAction::class)->handle($receivable, $approver, '2026-06-30');
            $this->fail('Conflicting primary account must block formation.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('primary account conflicts', $exception->getMessage());
        }

        $this->assertNull($receivable->refresh()->contract_outstanding_amount);
        $this->assertSame('3000010000000113', $receivable->loan_account_number);
    }

    public function test_trs_not_found_blocks_formation_instead_of_becoming_zero(): void
    {
        [$receivable, $approver] = $this->accountingValidationReceivable();
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse('100.00')),
            'http://contract.test/api/v1/loans/*/contractual*' => Http::response(['error' => 'not_found'], 404),
        ]);

        try {
            app(PrepareAccountingValidationContractOutstandingAction::class)->handle($receivable, $approver, '2026-06-30');
            $this->fail('TRS not_found must block formation.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('not found', $exception->getMessage());
        }

        $this->assertNull($receivable->refresh()->contract_outstanding_amount);
        $this->assertSame(404, ApiIntegrationLog::query()->where('service_name', 'contract_outstanding')->sole()->response_status);
    }

    public function test_positive_spread_requires_fresh_fincloud_product_mapping_but_zero_spread_does_not(): void
    {
        [$unmapped, $approver] = $this->accountingValidationReceivable();
        [$equal] = $this->accountingValidationReceivable();
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::sequence()
                ->push($this->loanResponse('100.00', '999'))
                ->push($this->loanResponse('100.00', '999')),
            'http://contract.test/api/v1/loans/*/contractual*' => Http::sequence()
                ->push($this->contractResponse('93.00'))
                ->push($this->contractResponse('100.00')),
        ]);

        try {
            app(PrepareAccountingValidationContractOutstandingAction::class)->handle($unmapped, $approver, '2026-06-30');
            $this->fail('Unmapped fresh product with positive spread must block.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Positive spread requires mapped LSA', $exception->getMessage());
        }
        $this->assertNull($unmapped->refresh()->contract_outstanding_amount);
        $this->assertSame('999', $unmapped->product_id);

        $stored = app(PrepareAccountingValidationContractOutstandingAction::class)->handle($equal, $approver, '2026-06-30');
        $this->assertSame('100.00', $stored->contract_outstanding_amount);
        $this->assertSame('999', $stored->contract_outstanding_product_code);
        $this->assertNull($stored->contract_outstanding_trx_type);
    }

    public function test_new_accounting_attempt_fetches_fresh_contract_after_branch_return(): void
    {
        [$receivable, $approver] = $this->accountingValidationReceivable([
            'loan_outstanding' => '100.00',
            'contract_outstanding_amount' => '93.00',
            'contract_outstanding_requested_as_of' => '2026-06-30',
            'contract_outstanding_as_of' => '2026-06-30',
            'contract_outstanding_product_code' => '301',
            'contract_outstanding_trx_type' => 'LSA01',
        ]);

        $receivable->forceFill([
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER,
        ])->save();
        $this->assertNull($receivable->refresh()->contract_outstanding_amount);

        $receivable->forceFill([
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
        ])->save();
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse('98.00')),
            'http://contract.test/api/v1/loans/*/contractual*' => Http::response($this->contractResponse('91')),
        ]);

        $fresh = app(PrepareAccountingValidationContractOutstandingAction::class)
            ->handle($receivable, $approver, '2026-06-30');

        $this->assertSame('91.00', $fresh->contract_outstanding_amount);
        $split = app(CalculateEarlyTerminationSplitTopUp::class)->handle($fresh->loan_outstanding, $fresh->contract_outstanding_amount);
        $this->assertSame('7.00', (string) $split->lsaTopUpAmount);
        $this->assertSame('91.00', (string) $split->piutangTopUpAmount);
        Http::assertSentCount(2);
    }

    public function test_accounting_approval_blocks_when_fresh_collectability_is_no_longer_five(): void
    {
        [$receivable, $approver] = $this->accountingValidationReceivable(['loan_outstanding' => '10000.00']);
        $loan = $this->loanResponse('10000.00');
        $loan['data']['collectability'] = '4';
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response($loan),
            'http://contract.test/api/v1/loans/*/contractual*' => Http::response($this->contractResponse('9000')),
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
            'http://contract.test/api/v1/loans/*/contractual*' => Http::response($this->contractResponse('9000')),
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

    private function loanResponse(string $outstanding, string $product = '301'): array
    {
        return [
            'responseCode' => '00',
            'description' => 'SUCCESS',
            'data' => [
                'accountNumber' => '3000010000000113',
                'altNumber' => 'ALT-1',
                'branchCode' => '001',
                'collectability' => '5',
                'productID' => $product,
                'productName' => 'Kredit Pegawai Aktif',
                'loanOutStanding' => $outstanding,
                'installmentAmount' => '1000.00',
                'nextDueDate' => '20260630',
                'saForLoanRepayment' => '001000OPER',
            ],
        ];
    }

    private function contractResponse(int|string $amount, string $requested = '3000010000000113', string $primary = '3000010000000113', string $asOf = '2026-06-30'): string
    {
        return '{"requested_account":'.json_encode($requested).',"primary_account":'.json_encode($primary).',"as_of":'.json_encode($asOf).',"contract_rate":12.50,"contractual_outstanding":'.$amount.',"position_source":"DWH","repayment_history":[]}';
    }
}
