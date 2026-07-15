<?php

namespace Tests\Feature;

use App\Actions\InsuranceReceivable\ExecuteEarlyTerminationWithRepaymentTopUpAction;
use App\Filament\Resources\InsuranceReceivables\Pages\ViewInsuranceReceivable;
use App\Jobs\ExecuteEarlyTerminationJob;
use App\Models\ApiIntegrationLog;
use App\Models\BranchOffice;
use App\Models\EarlyTerminationBalanceInquiry;
use App\Models\EarlyTerminationTransaction;
use App\Models\GlToGlTransaction;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\CoreBanking\CoreBankingClient;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class EarlyTerminationRepaymentPreflightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'core_banking.base_url' => 'http://core.test',
            'core_banking.signature_secret' => 'secret-key',
        ]);
        Carbon::setTestNow('2026-06-22 10:20:30');
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

    public function test_balance_inquiry_schema_indexes_and_relations_work(): void
    {
        $receivable = $this->receivable();
        $user = $this->accountingApprover();
        $apiLog = ApiIntegrationLog::query()->create([
            'service_name' => 'core_banking',
            'endpoint' => '/saving/inq/balance',
            'method' => 'GET',
            'is_success' => true,
            'requested_by' => $user->id,
        ]);
        $older = EarlyTerminationBalanceInquiry::query()->create([
            'insurance_receivable_id' => $receivable->id,
            'saving_account_number' => '1000010000000691',
            'loan_outstanding_amount' => '1000.00',
            'available_balance' => '250.00',
            'required_top_up_amount' => '750.00',
            'status' => EarlyTerminationBalanceInquiry::STATUS_SUCCESS,
            'requested_by' => $user->id,
            'requested_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
        ]);
        $latest = EarlyTerminationBalanceInquiry::query()->create([
            'insurance_receivable_id' => $receivable->id,
            'api_integration_log_id' => $apiLog->id,
            'saving_account_number' => '1000010000000691',
            'loan_outstanding_amount' => '1000.00',
            'available_balance' => '400.00',
            'required_top_up_amount' => '600.00',
            'response_code' => '00',
            'response_description' => 'SUCCESS',
            'status' => EarlyTerminationBalanceInquiry::STATUS_SUCCESS,
            'requested_by' => $user->id,
            'requested_at' => now(),
            'completed_at' => now(),
        ]);
        $transaction = GlToGlTransaction::query()->create([
            'purpose' => GlToGlTransaction::PURPOSE_EARLY_TERMINATION_REPAYMENT_TOP_UP,
            'insurance_receivable_id' => $receivable->id,
            'early_termination_balance_inquiry_id' => $latest->id,
            'reference_number' => 'ETTOP-SCHEMA',
            'receipt_number' => 'ETTOP-SCHEMA-R',
            'status' => GlToGlTransaction::STATUS_PENDING,
        ]);

        $this->assertTrue(Schema::hasTable('early_termination_balance_inquiries'));
        $this->assertTrue(Schema::hasColumns('early_termination_balance_inquiries', [
            'insurance_receivable_id',
            'api_integration_log_id',
            'saving_account_number',
            'loan_outstanding_amount',
            'available_balance',
            'required_top_up_amount',
            'response_code',
            'response_description',
            'status',
            'error_message',
            'requested_by',
            'requested_at',
            'completed_at',
        ]));
        $this->assertTrue(Schema::hasColumn('gl_to_gl_transactions', 'early_termination_balance_inquiry_id'));
        $this->assertSqliteIndexExists('early_termination_balance_inquiries', 'et_balance_inquiries_receivable_id_index');
        $this->assertSqliteIndexExists('early_termination_balance_inquiries', 'et_balance_inquiries_api_log_id_index');
        $this->assertSqliteIndexExists('early_termination_balance_inquiries', 'et_balance_inquiries_requested_by_index');
        $this->assertSqliteIndexExists('early_termination_balance_inquiries', 'early_termination_balance_inquiries_requested_at_index');
        $this->assertSqliteIndexExists('early_termination_balance_inquiries', 'et_balance_inquiries_receivable_id_id_index');
        $this->assertSqliteIndexExists('gl_to_gl_transactions', 'gl_to_gl_et_balance_inquiry_id_index');

        $this->assertTrue($latest->insuranceReceivable->is($receivable));
        $this->assertTrue($latest->apiIntegrationLog->is($apiLog));
        $this->assertTrue($latest->requester->is($user));
        $this->assertTrue($apiLog->earlyTerminationBalanceInquiry->is($latest));
        $this->assertTrue($transaction->earlyTerminationBalanceInquiry->is($latest));
        $this->assertTrue($latest->glToGlTransaction->is($transaction));
        $this->assertTrue($receivable->latestEarlyTerminationBalanceInquiry->is($latest));
        $this->assertTrue($receivable->latestEarlyTerminationTopUpTransaction->is($transaction));
        $this->assertFalse($receivable->latestEarlyTerminationBalanceInquiry->is($older));
    }

    public function test_balance_inquiry_is_unsigned_and_logs_success_and_failure(): void
    {
        $receivable = $this->receivable();
        $user = $this->accountingApprover();
        Http::fake([
            'http://core.test/saving/inq/balance*' => Http::sequence()
                ->push($this->balanceResponse('48271.64'))
                ->push(['responseCode' => '99', 'description' => 'Unavailable', 'data' => []], 503),
        ]);

        $success = app(CoreBankingClient::class)->inquireBalance(
            $receivable->saving_account_for_loan_repayment,
            $receivable,
            $user,
        );
        $failure = app(CoreBankingClient::class)->inquireBalance(
            $receivable->saving_account_for_loan_repayment,
            $receivable,
            $user,
        );

        $this->assertTrue($success['ok']);
        $this->assertFalse($failure['ok']);
        $this->assertSame('48271.64', $success['data']['availableBalance']);
        $this->assertSame(2, ApiIntegrationLog::query()->where('endpoint', '/saving/inq/balance')->count());
        $this->assertDatabaseHas('api_integration_logs', [
            'related_type' => InsuranceReceivable::class,
            'related_id' => $receivable->id,
            'method' => 'GET',
            'response_code' => '99',
            'is_success' => false,
        ]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://core.test/saving/inq/balance?accountNumber=1000010000000691'
            && ! $request->hasHeader('Signature'));
    }

    public function test_empty_and_oper_accounts_require_manual_early_termination_without_api_calls(): void
    {
        Http::fake();
        $action = app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class);
        $user = $this->accountingApprover();
        $empty = $this->receivable(['saving_account_for_loan_repayment' => '   ']);
        $oper = $this->receivable(['saving_account_for_loan_repayment' => '  xx-oPeR-01  ']);

        $this->assertNull($action->handle($empty, $user));
        $this->assertSame(
            InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED,
            $empty->refresh()->system_status,
        );
        $this->assertSame(
            InsuranceReceivable::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_PENDING,
            $empty->workflow_status,
        );
        $this->assertSame(
            'Manual Early Termination execution required because repayment saving account is empty.',
            $empty->last_error_message,
        );

        $this->assertNull($action->handle($oper, $user));
        $this->assertSame(
            InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED,
            $oper->refresh()->system_status,
        );
        $this->assertSame(
            InsuranceReceivable::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_PENDING,
            $oper->workflow_status,
        );
        $this->assertSame('Manual Early Termination execution required for OPER account.', $oper->last_error_message);
        $this->assertTrue($oper->stageLogs()->where('event', 'early_termination_manual_execution_required')->exists());
        $this->assertDatabaseCount('gl_to_gl_transactions', 0);
        $this->assertDatabaseCount('early_termination_transactions', 0);
        $this->assertDatabaseCount('early_termination_balance_inquiries', 0);
        Http::assertNothingSent();
    }

    public function test_failed_balance_inquiry_blocks_early_termination(): void
    {
        $receivable = $this->receivable();
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse()),
            'http://core.test/saving/inq/balance*' => Http::response([
                'responseCode' => '91',
                'description' => 'Balance service unavailable',
                'data' => [],
            ]),
        ]);

        $result = app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)
            ->handle($receivable, $this->accountingApprover());

        $this->assertNull($result);
        $this->assertSame(
            InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_TOP_UP_FAILED,
            $receivable->refresh()->system_status,
        );
        $this->assertSame('Balance service unavailable', $receivable->last_error_message);
        $this->assertDatabaseCount('gl_to_gl_transactions', 0);
        $this->assertDatabaseCount('early_termination_transactions', 0);
        $inquiry = EarlyTerminationBalanceInquiry::query()->sole();
        $this->assertSame($receivable->id, $inquiry->insurance_receivable_id);
        $this->assertSame(EarlyTerminationBalanceInquiry::STATUS_FAILED, $inquiry->status);
        $this->assertSame('91', $inquiry->response_code);
        $this->assertSame('Balance service unavailable', $inquiry->response_description);
        $this->assertNull($inquiry->available_balance);
        $this->assertNotNull($inquiry->api_integration_log_id);
        Http::assertSentCount(2);
    }

    public function test_balance_timeout_creates_timeout_inquiry_and_blocks_early_termination(): void
    {
        $receivable = $this->receivable();
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse()),
            'http://core.test/saving/inq/balance*' => fn () => throw new ConnectionException('Connection timed out.'),
        ]);

        $result = app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)
            ->handle($receivable, $this->accountingApprover());

        $this->assertNull($result);
        $this->assertSame(
            InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_TOP_UP_FAILED,
            $receivable->refresh()->system_status,
        );
        $inquiry = EarlyTerminationBalanceInquiry::query()->sole();
        $this->assertSame(EarlyTerminationBalanceInquiry::STATUS_TIMEOUT, $inquiry->status);
        $this->assertSame('Connection timed out.', $inquiry->error_message);
        $this->assertNotNull($inquiry->api_integration_log_id);
        $this->assertDatabaseCount('gl_to_gl_transactions', 0);
        $this->assertDatabaseCount('early_termination_transactions', 0);
    }

    public function test_parse_failed_balance_inquiry_blocks_early_termination(): void
    {
        $receivable = $this->receivable();
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse()),
            'http://core.test/saving/inq/balance*' => Http::response([
                'responseCode' => '00',
                'description' => 'SUCCESS',
                'data' => ['availableBalance' => 'not-a-number'],
            ]),
        ]);

        $result = app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)
            ->handle($receivable, $this->accountingApprover());

        $this->assertNull($result);
        $inquiry = EarlyTerminationBalanceInquiry::query()->sole();
        $this->assertSame(EarlyTerminationBalanceInquiry::STATUS_PARSE_FAILED, $inquiry->status);
        $this->assertSame('00', $inquiry->response_code);
        $this->assertNull($inquiry->available_balance);
        $this->assertDatabaseCount('gl_to_gl_transactions', 0);
        $this->assertDatabaseCount('early_termination_transactions', 0);
    }

    public function test_sufficient_decimal_balance_skips_gl_and_executes_early_termination(): void
    {
        $receivable = $this->receivable(['loan_outstanding' => '1000.10']);
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::sequence()
                ->push($this->loanResponse('1000.10'))
                ->push($this->loanResponse('1000.10')),
            'http://core.test/saving/inq/balance*' => Http::sequence()
                ->push($this->balanceResponse('1000.100'))
                ->push($this->balanceResponse('1000.100'))
                ->push($this->balanceResponse('1000.100')),
            'http://core.test/loan/earlytermination/' => Http::response($this->earlyTerminationSuccess()),
        ]);

        $result = app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)
            ->handle($receivable, $this->accountingApprover());

        $this->assertSame(
            EarlyTerminationTransaction::STATUS_SUCCESS,
            $result?->status,
            $receivable->refresh()->last_error_message ?? '',
        );
        $this->assertDatabaseCount('gl_to_gl_transactions', 0);
        $this->assertSame(
            InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_EXECUTED,
            $receivable->refresh()->workflow_status,
        );
        $inquiry = EarlyTerminationBalanceInquiry::query()
            ->where('context', EarlyTerminationBalanceInquiry::CONTEXT_PRE_TOP_UP)
            ->sole();
        $this->assertSame(EarlyTerminationBalanceInquiry::STATUS_SUCCESS, $inquiry->status);
        $this->assertSame('1000.10', $inquiry->available_balance);
        $this->assertSame('0.00', $inquiry->required_top_up_amount);
        $this->assertSame('1000.10', $inquiry->loan_outstanding_amount);
        $this->assertSame(3, EarlyTerminationBalanceInquiry::query()->count());
        Http::assertSentCount(6);
    }

    public function test_positive_shortage_executes_exact_piutang_asuransi_payload_then_early_termination(): void
    {
        $receivable = $this->receivable(['loan_outstanding' => '1000.105']);
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::sequence()
                ->push($this->loanResponse('1000.105'))
                ->push($this->loanResponse('1000.105')),
            'http://core.test/saving/inq/balance*' => Http::sequence()
                ->push($this->balanceResponse('400.00'))
                ->push($this->balanceResponse('400.00'))
                ->push($this->balanceResponse('1000.105')),
            'http://core.test/trx/transfer/gl-to-gl' => Http::response([
                'responseCode' => '00',
                'description' => 'Top up success',
                'data' => [],
            ]),
            'http://core.test/loan/earlytermination/' => Http::response($this->earlyTerminationSuccess()),
        ]);

        $result = app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)
            ->handle($receivable, $this->accountingApprover());
        $transaction = GlToGlTransaction::query()->sole();

        $this->assertSame(
            EarlyTerminationTransaction::STATUS_SUCCESS,
            $result?->status,
            $receivable->refresh()->last_error_message ?? '',
        );
        $this->assertSame(GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP, $transaction->purpose);
        $this->assertSame($receivable->id, $transaction->insurance_receivable_id);
        $inquiry = EarlyTerminationBalanceInquiry::query()
            ->where('context', EarlyTerminationBalanceInquiry::CONTEXT_PRE_CONTRACT_TOP_UP)
            ->sole();
        $this->assertSame($inquiry->id, $transaction->early_termination_balance_inquiry_id);
        $this->assertSame(EarlyTerminationBalanceInquiry::STATUS_SUCCESS, $inquiry->status);
        $this->assertSame('400.00', $inquiry->available_balance);
        $this->assertSame('600.11', $inquiry->required_top_up_amount);
        $this->assertSame(3, ApiIntegrationLog::query()->where('endpoint', '/saving/inq/balance')->count());
        $this->assertSame("ETPIU-{$receivable->id}-001", $transaction->reference_number);
        $this->assertSame($transaction->reference_number, $transaction->receipt_number);
        $this->assertSame('PiutangAsuransi', $transaction->request_payload['trxType']);
        $this->assertSame('', $transaction->request_payload['debitAccount']);
        $this->assertSame('', $transaction->request_payload['creditAccount']);
        $this->assertSame('1000010000000691', $transaction->request_payload['destAccount']);
        $this->assertSame('001', $transaction->request_payload['branchCode']);
        $this->assertSame('600.11', $transaction->request_payload['amount']);
        $this->assertSame(GlToGlTransaction::STATUS_SUCCESS, $transaction->status);
    }

    public function test_failed_top_up_retry_uses_new_reference_and_keeps_failed_attempt(): void
    {
        $receivable = $this->receivable(['loan_outstanding' => '1000.00']);
        $user = $this->accountingApprover();
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::sequence()
                ->push($this->loanResponse('1000.00'))
                ->push($this->loanResponse('1000.00'))
                ->push($this->loanResponse('1000.00')),
            'http://core.test/saving/inq/balance*' => Http::sequence()
                ->push($this->balanceResponse('0'))
                ->push($this->balanceResponse('0'))
                ->push($this->balanceResponse('0'))
                ->push($this->balanceResponse('0'))
                ->push($this->balanceResponse('1000.00')),
            'http://core.test/trx/transfer/gl-to-gl' => Http::sequence()
                ->push(['unexpected' => 'timeout'])
                ->push([
                    'responseCode' => '00',
                    'description' => 'Top up success',
                    'data' => [],
                ]),
            'http://core.test/loan/earlytermination/' => Http::response($this->earlyTerminationSuccess()),
        ]);

        $this->assertNull(app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)->handle($receivable, $user));
        $first = GlToGlTransaction::query()->sole();
        $firstInquiry = EarlyTerminationBalanceInquiry::query()
            ->where('context', EarlyTerminationBalanceInquiry::CONTEXT_PRE_CONTRACT_TOP_UP)
            ->sole();
        $firstReference = $first->reference_number;
        $firstPayload = $first->request_payload;
        $firstInquiryId = $first->early_termination_balance_inquiry_id;

        $result = app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)->handle($receivable->refresh(), $user);
        $retried = GlToGlTransaction::query()->latest('id')->firstOrFail();
        $latestInquiry = EarlyTerminationBalanceInquiry::query()->latest('id')->firstOrFail();

        $this->assertSame(
            EarlyTerminationTransaction::STATUS_SUCCESS,
            $result?->status,
            $receivable->refresh()->last_error_message ?? '',
        );
        $this->assertNotSame($first->id, $retried->id);
        $this->assertSame($firstReference, $first->refresh()->reference_number);
        $this->assertSame("ETPIU-{$receivable->id}-002", $retried->reference_number);
        $this->assertSame($firstPayload['amount'], $retried->request_payload['amount']);
        $this->assertSame('1000.00', $retried->request_payload['amount']);
        $this->assertSame($firstInquiry->id, $firstInquiryId);
        $this->assertNotSame($firstInquiryId, $retried->early_termination_balance_inquiry_id);
        $this->assertSame(GlToGlTransaction::STATUS_FAILED, $first->refresh()->status);
        $this->assertSame(5, EarlyTerminationBalanceInquiry::query()->count());
        $this->assertSame('0.00', $latestInquiry->required_top_up_amount);
        $this->assertSame(EarlyTerminationBalanceInquiry::CONTEXT_POST_TOP_UP_VERIFICATION, $latestInquiry->context);
        $this->assertSame(2, ApiIntegrationLog::query()->where('endpoint', '/trx/transfer/gl-to-gl')->count());
    }

    public function test_sufficient_balance_after_failed_top_up_skips_resend_and_executes_early_termination(): void
    {
        $receivable = $this->receivable(['loan_outstanding' => '1000.00']);
        $user = $this->accountingApprover();
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::sequence()
                ->push($this->loanResponse('1000.00'))
                ->push($this->loanResponse('1000.00'))
                ->push($this->loanResponse('1000.00')),
            'http://core.test/saving/inq/balance*' => Http::sequence()
                ->push($this->balanceResponse('0'))
                ->push($this->balanceResponse('0'))
                ->push($this->balanceResponse('1000.00'))
                ->push($this->balanceResponse('1000.00'))
                ->push($this->balanceResponse('1000.00'))
                ->push($this->balanceResponse('1000.00')),
            'http://core.test/trx/transfer/gl-to-gl' => Http::response(['unexpected' => 'unknown']),
            'http://core.test/loan/earlytermination/' => Http::response($this->earlyTerminationSuccess()),
        ]);
        app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)->handle($receivable, $user);
        $transactionBeforeRetry = GlToGlTransaction::query()->sole();
        $payloadBeforeRetry = $transactionBeforeRetry->request_payload;
        $fkBeforeRetry = $transactionBeforeRetry->early_termination_balance_inquiry_id;

        $result = app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)->handle($receivable->refresh(), $user);
        $transactionAfterRetry = GlToGlTransaction::query()->sole();

        $this->assertSame(
            EarlyTerminationTransaction::STATUS_SUCCESS,
            $result?->status,
            $receivable->refresh()->last_error_message ?? '',
        );
        $this->assertSame(1, ApiIntegrationLog::query()->where('endpoint', '/trx/transfer/gl-to-gl')->count());
        $this->assertSame(5, EarlyTerminationBalanceInquiry::query()->count());
        $this->assertSame(GlToGlTransaction::STATUS_FAILED, $transactionAfterRetry->status);
        $this->assertSame(GlToGlTransaction::RESOLUTION_STATUS_NO_LONGER_REQUIRED, $transactionAfterRetry->resolution_status);
        $this->assertSame($payloadBeforeRetry, $transactionAfterRetry->request_payload);
        $this->assertSame($fkBeforeRetry, $transactionAfterRetry->early_termination_balance_inquiry_id);
        $this->assertNotNull($transactionAfterRetry->resolved_at);
    }

    public function test_transport_timeout_marks_component_for_reconciliation_without_blind_retry(): void
    {
        $receivable = $this->receivable(['loan_outstanding' => '1000.00']);
        $user = $this->accountingApprover();
        $glAttempts = 0;
        Http::fake(function (Request $request) use (&$glAttempts) {
            if (str_contains($request->url(), '/inquiry/detail/loan')) {
                return Http::response($this->loanResponse('1000.00'));
            }

            if (str_contains($request->url(), '/saving/inq/balance')) {
                return Http::response($this->balanceResponse('0'));
            }

            if (str_contains($request->url(), '/trx/transfer/gl-to-gl')) {
                $glAttempts++;

                if ($glAttempts === 1) {
                    throw new ConnectionException('Connection timed out.');
                }

                return Http::response([
                    'responseCode' => '00',
                    'description' => 'Top up success',
                    'data' => [],
                ]);
            }

            return Http::response($this->earlyTerminationSuccess());
        });

        $this->assertNull(app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)->handle($receivable, $user));
        $first = GlToGlTransaction::query()->sole();
        $reference = $first->reference_number;
        $this->assertSame('Connection timed out.', $first->response_description);
        $this->assertSame(GlToGlTransaction::STATUS_UNKNOWN_TIMEOUT, $first->status);
        $this->assertSame(GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED, $first->resolution_status);

        $result = app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)->handle($receivable->refresh(), $user);
        $retried = GlToGlTransaction::query()->sole();

        $this->assertNull($result);
        $this->assertSame($first->id, $retried->id);
        $this->assertSame($reference, $retried->reference_number);
        $this->assertSame(4, EarlyTerminationBalanceInquiry::query()->count());
        $this->assertSame($first->early_termination_balance_inquiry_id, $retried->early_termination_balance_inquiry_id);
        $this->assertSame(1, ApiIntegrationLog::query()->where('endpoint', '/trx/transfer/gl-to-gl')->count());
        $this->assertSame(1, $glAttempts);
    }

    public function test_successful_top_up_is_not_repeated_when_early_termination_retries(): void
    {
        $receivable = $this->receivable(['loan_outstanding' => '1000.00']);
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::sequence()
                ->push($this->loanResponse('1000.00'))
                ->push($this->loanResponse('1000.00'))
                ->push($this->loanResponse('1000.00'))
                ->push($this->loanResponse('1000.00')),
            'http://core.test/saving/inq/balance*' => Http::sequence()
                ->push($this->balanceResponse('0'))
                ->push($this->balanceResponse('0'))
                ->push($this->balanceResponse('1000.00'))
                ->push($this->balanceResponse('1000.00'))
                ->push($this->balanceResponse('1000.00'))
                ->push($this->balanceResponse('1000.00')),
            'http://core.test/trx/transfer/gl-to-gl' => Http::response([
                'responseCode' => '00',
                'description' => 'Top up success',
                'data' => [],
            ]),
            'http://core.test/loan/earlytermination/' => Http::sequence()
                ->push(['responseCode' => '99', 'description' => 'ET failed', 'data' => []])
                ->push($this->earlyTerminationSuccess()),
        ]);
        $action = app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class);
        $user = $this->accountingApprover();

        $first = $action->handle($receivable, $user);
        $second = $action->handle($receivable->refresh(), $user);

        $this->assertSame(EarlyTerminationTransaction::STATUS_FAILED, $first?->status);
        $this->assertSame(EarlyTerminationTransaction::STATUS_SUCCESS, $second?->status);
        $this->assertDatabaseCount('gl_to_gl_transactions', 1);
        $this->assertDatabaseCount('early_termination_balance_inquiries', 6);
        $this->assertSame(6, ApiIntegrationLog::query()->where('endpoint', '/saving/inq/balance')->count());
        $this->assertSame(1, ApiIntegrationLog::query()->where('endpoint', '/trx/transfer/gl-to-gl')->count());
    }

    public function test_infolist_reads_latest_balance_inquiry_from_domain_model(): void
    {
        $receivable = $this->receivable();
        ApiIntegrationLog::query()->create([
            'service_name' => 'core_banking',
            'endpoint' => '/saving/inq/balance',
            'method' => 'GET',
            'response_body' => ['data' => ['availableBalance' => '999999.00']],
            'response_code' => '00',
            'response_description' => 'OLD LOG',
            'is_success' => true,
            'related_type' => InsuranceReceivable::class,
            'related_id' => $receivable->id,
            'requested_at' => now(),
        ]);
        EarlyTerminationBalanceInquiry::query()->create([
            'insurance_receivable_id' => $receivable->id,
            'saving_account_number' => '1000010000000691',
            'loan_outstanding_amount' => '1000.00',
            'available_balance' => '48271.64',
            'required_top_up_amount' => '0.00',
            'response_code' => '00',
            'response_description' => 'SUCCESS',
            'status' => EarlyTerminationBalanceInquiry::STATUS_SUCCESS,
            'requested_by' => $this->accountingApprover()->id,
            'requested_at' => now(),
            'completed_at' => now(),
        ]);

        Livewire::actingAs($this->accountingApprover())
            ->test(ViewInsuranceReceivable::class, ['record' => $receivable->id])
            ->assertSee('Early termination top up')
            ->assertSee('48.272')
            ->assertSee('SUCCESS')
            ->assertSee('success')
            ->assertSee('1000010000000691')
            ->assertDontSee('OLD LOG')
            ->assertDontSee('999.999');
    }

    public function test_infolist_top_up_tab_is_visible_when_only_top_up_exists(): void
    {
        $receivable = $this->receivable();
        GlToGlTransaction::query()->create([
            'purpose' => GlToGlTransaction::PURPOSE_EARLY_TERMINATION_REPAYMENT_TOP_UP,
            'insurance_receivable_id' => $receivable->id,
            'reference_number' => 'ETTOP-ONLY',
            'receipt_number' => 'ETTOP-ONLY-R',
            'request_payload' => ['amount' => '1000.00'],
            'status' => GlToGlTransaction::STATUS_FAILED,
            'response_description' => 'Top up failed',
        ]);

        Livewire::actingAs($this->accountingApprover())
            ->test(ViewInsuranceReceivable::class, ['record' => $receivable->id])
            ->assertSee('Early termination top up')
            ->assertSee('ETTOP-ONLY')
            ->assertSee('Top up failed');
    }

    public function test_infolist_shows_lsa_top_up_when_piutang_row_is_newer(): void
    {
        $receivable = $this->receivable();
        $lsa = GlToGlTransaction::query()->create([
            'purpose' => GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP,
            'insurance_receivable_id' => $receivable->id,
            'reference_number' => 'ETLSA-'.$receivable->id,
            'receipt_number' => 'ETLSA-'.$receivable->id,
            'request_payload' => ['trxType' => 'LSA01', 'amount' => '2278681.31'],
            'status' => GlToGlTransaction::STATUS_SUCCESS,
            'response_description' => 'LSA success',
        ]);
        GlToGlTransaction::query()->create([
            'purpose' => GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP,
            'insurance_receivable_id' => $receivable->id,
            'reference_number' => 'ETPIU-'.$receivable->id,
            'receipt_number' => 'ETPIU-'.$receivable->id,
            'request_payload' => ['trxType' => 'PiutangAsuransi', 'amount' => '56872763.00'],
            'status' => GlToGlTransaction::STATUS_SUCCESS,
            'response_description' => 'Piutang success',
        ]);

        $this->assertTrue($receivable->refresh()->latestEarlyTerminationFlatSpreadTopUpTransaction->is($lsa));

        Livewire::actingAs($this->accountingApprover())
            ->test(ViewInsuranceReceivable::class, ['record' => $receivable->id])
            ->assertSee('ETLSA-'.$receivable->id)
            ->assertSee('2.278.681')
            ->assertSee('success')
            ->assertSee('LSA success');
    }

    public function test_infolist_shows_split_inquiry_fields_when_post_verification_is_newer(): void
    {
        $receivable = $this->receivable();
        EarlyTerminationBalanceInquiry::query()->create([
            'insurance_receivable_id' => $receivable->id,
            'context' => EarlyTerminationBalanceInquiry::CONTEXT_PRE_TOP_UP,
            'saving_account_number' => '1000010000000691',
            'loan_outstanding_amount' => '10000000.00',
            'available_balance' => '1000000.00',
            'required_top_up_amount' => '9000000.00',
            'contract_outstanding_amount' => '7000000.00',
            'spread_amount' => '3000000.00',
            'total_shortage_amount' => '9000000.00',
            'lsa_top_up_amount' => '2000000.00',
            'piutang_top_up_amount' => '7000000.00',
            'status' => EarlyTerminationBalanceInquiry::STATUS_SUCCESS,
            'requested_by' => $this->accountingApprover()->id,
            'requested_at' => now()->subMinutes(2),
            'completed_at' => now()->subMinutes(2),
        ]);
        $preContract = EarlyTerminationBalanceInquiry::query()->create([
            'insurance_receivable_id' => $receivable->id,
            'context' => EarlyTerminationBalanceInquiry::CONTEXT_PRE_CONTRACT_TOP_UP,
            'saving_account_number' => '1000010000000691',
            'loan_outstanding_amount' => '10000000.00',
            'available_balance' => '2222222.00',
            'required_top_up_amount' => '7777778.00',
            'contract_outstanding_amount' => '7000000.00',
            'spread_amount' => '3000000.00',
            'total_shortage_amount' => '7777778.00',
            'lsa_top_up_amount' => '1234567.00',
            'piutang_top_up_amount' => '6543211.00',
            'status' => EarlyTerminationBalanceInquiry::STATUS_SUCCESS,
            'requested_by' => $this->accountingApprover()->id,
            'requested_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
        ]);
        EarlyTerminationBalanceInquiry::query()->create([
            'insurance_receivable_id' => $receivable->id,
            'context' => EarlyTerminationBalanceInquiry::CONTEXT_POST_TOP_UP_VERIFICATION,
            'saving_account_number' => '1000010000000691',
            'loan_outstanding_amount' => '10000000.00',
            'available_balance' => '10000000.00',
            'required_top_up_amount' => '0.00',
            'status' => EarlyTerminationBalanceInquiry::STATUS_SUCCESS,
            'requested_by' => $this->accountingApprover()->id,
            'requested_at' => now(),
            'completed_at' => now(),
        ]);
        GlToGlTransaction::query()->create([
            'purpose' => GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP,
            'insurance_receivable_id' => $receivable->id,
            'reference_number' => 'ETLSA-'.$receivable->id,
            'receipt_number' => 'ETLSA-'.$receivable->id,
            'request_payload' => ['amount' => '1234567.00'],
            'status' => GlToGlTransaction::STATUS_SUCCESS,
        ]);
        GlToGlTransaction::query()->create([
            'purpose' => GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP,
            'insurance_receivable_id' => $receivable->id,
            'reference_number' => 'ETPIU-'.$receivable->id,
            'receipt_number' => 'ETPIU-'.$receivable->id,
            'request_payload' => ['amount' => '6543211.00'],
            'status' => GlToGlTransaction::STATUS_SUCCESS,
        ]);

        $fresh = $receivable->refresh();
        $this->assertTrue($fresh->latestCalculationInquiry->is($preContract));
        $this->assertTrue($fresh->latestPreContractTopUpInquiry->is($preContract));
        $this->assertNull($fresh->latestEarlyTerminationFlatSpreadTopUpTransaction->resolution_status);

        Livewire::actingAs($this->accountingApprover())
            ->test(ViewInsuranceReceivable::class, ['record' => $receivable->id])
            ->assertSee('Current LSA required')
            ->assertSee('Current Piutang required')
            ->assertSee('1.234.567')
            ->assertSee('6.543.211')
            ->assertSee('2.222.222')
            ->assertDontSee('LSA top up amount')
            ->assertDontSee('Piutang top up amount')
            ->assertDontSee('no_longer_required')
            ->assertDontSee('reconciliation_required')
            ->assertDontSee('resolved_manually');
    }

    public function test_authorized_accounting_user_can_queue_initial_execution_only_once(): void
    {
        Queue::fake();
        $receivable = $this->receivable([
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_CONFIRMATION_PENDING,
        ]);
        $approver = $this->accountingApprover();

        Livewire::actingAs($approver)
            ->test(ViewInsuranceReceivable::class, ['record' => $receivable->id])
            ->assertActionVisible('executeEarlyTermination')
            ->callAction('executeEarlyTermination');

        $this->assertSame(
            InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_QUEUED,
            $receivable->refresh()->system_status,
        );
        Queue::assertPushed(ExecuteEarlyTerminationJob::class, 1);

        Livewire::actingAs($this->userWithRole('accounting_maker', '000'))
            ->test(ViewInsuranceReceivable::class, ['record' => $receivable->id])
            ->assertActionHidden('executeEarlyTermination');
    }

    public function test_ckpn_rows_allow_null_receivable_and_top_up_row_is_reused(): void
    {
        GlToGlTransaction::query()->create([
            'purpose' => GlToGlTransaction::PURPOSE_CKPN_JOURNAL,
            'reference_number' => 'CKPN-1',
            'receipt_number' => 'CKPN-R-1',
        ]);
        GlToGlTransaction::query()->create([
            'purpose' => GlToGlTransaction::PURPOSE_CKPN_JOURNAL,
            'reference_number' => 'CKPN-2',
            'receipt_number' => 'CKPN-R-2',
        ]);

        $this->assertSame(2, GlToGlTransaction::query()
            ->where('purpose', GlToGlTransaction::PURPOSE_CKPN_JOURNAL)
            ->whereNull('insurance_receivable_id')
            ->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function receivable(array $attributes = []): InsuranceReceivable
    {
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $loanOutstanding = (string) ($attributes['loan_outstanding'] ?? '1000.00');
        $contractOutstanding = (string) ($attributes['contract_outstanding_amount'] ?? $loanOutstanding);

        return InsuranceReceivable::factory()->create([
            'branch_office_id' => $branch->id,
            'branch_code' => $branch->branch_code,
            'loan_account_number' => '3010010000000068',
            'saving_account_for_loan_repayment' => '1000010000000691',
            'loan_outstanding' => $loanOutstanding,
            'contract_outstanding_amount' => $contractOutstanding,
            'contract_outstanding_requested_as_of' => '2026-06-30',
            'contract_outstanding_as_of' => '2026-06-30',
            'contract_outstanding_product_code' => '301',
            'contract_outstanding_trx_type' => 'LSA01',
            'receivable_amount' => $contractOutstanding,
            'remaining_receivable_amount' => $contractOutstanding,
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_PROCESSING,
            ...$attributes,
        ]);
    }

    private function accountingApprover(): User
    {
        return $this->userWithRole('accounting_approver', '000');
    }

    private function userWithRole(string $role, string $branchCode): User
    {
        $branch = BranchOffice::query()->where('branch_code', $branchCode)->firstOrFail();
        $user = User::factory()->create(['branch_office_id' => $branch->id]);
        $user->assignRole($role);

        return $user;
    }

    private function assertSqliteIndexExists(string $table, string $index): void
    {
        $indexes = collect(DB::select("PRAGMA index_list('{$table}')"))
            ->pluck('name')
            ->all();

        $this->assertContains($index, $indexes);
    }

    /**
     * @return array<string, mixed>
     */
    private function loanResponse(string $outstanding = '1000.00'): array
    {
        return [
            'responseCode' => '00',
            'description' => 'SUCCESS',
            'data' => [
                'accountNumber' => '3010010000000068',
                'altNumber' => 'ALT-1',
                'branchCode' => '001',
                'loanOutStanding' => $outstanding,
                'saForLoanRepayment' => '1000010000000691',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function balanceResponse(string $availableBalance): array
    {
        return [
            'responseCode' => '00',
            'description' => 'SUCCESS',
            'data' => [
                'account' => '1000010000000691',
                'availableBalance' => $availableBalance,
                'currency' => 'IDR',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function earlyTerminationSuccess(): array
    {
        return [
            'responseCode' => '00',
            'description' => 'Success',
            'data' => ['transactionId' => 'ET-TRX-1'],
        ];
    }
}
