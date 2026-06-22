<?php

namespace Tests\Feature;

use App\Actions\InsuranceReceivable\ExecuteEarlyTerminationWithRepaymentTopUpAction;
use App\Filament\Resources\InsuranceReceivables\Pages\ViewInsuranceReceivable;
use App\Jobs\ExecuteEarlyTerminationJob;
use App\Models\ApiIntegrationLog;
use App\Models\BranchOffice;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    public function test_balance_inquiry_is_unsigned_and_logs_success_and_failure(): void
    {
        $receivable = $this->receivable();
        $user = $this->accountingApprover();
        Http::fake([
            'http://core.test/account/balance*' => Http::sequence()
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
        $this->assertSame(2, ApiIntegrationLog::query()->where('endpoint', '/account/balance')->count());
        $this->assertDatabaseHas('api_integration_logs', [
            'related_type' => InsuranceReceivable::class,
            'related_id' => $receivable->id,
            'method' => 'GET',
            'response_code' => '99',
            'is_success' => false,
        ]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://core.test/account/balance?account=1000010000000691'
            && ! $request->hasHeader('Signature'));
    }

    public function test_empty_and_oper_accounts_require_manual_top_up_without_api_calls(): void
    {
        Http::fake();
        $action = app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class);
        $user = $this->accountingApprover();
        $empty = $this->receivable(['saving_account_for_loan_repayment' => '   ']);
        $oper = $this->receivable(['saving_account_for_loan_repayment' => '  xx-oPeR-01  ']);

        $this->assertNull($action->handle($empty, $user));
        $this->assertSame(
            InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_TOP_UP_REQUIRED,
            $empty->refresh()->system_status,
        );
        $this->assertSame(
            'Manual top up required because repayment saving account is empty.',
            $empty->last_error_message,
        );

        $this->assertNull($action->handle($oper, $user));
        $this->assertSame(
            InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_TOP_UP_REQUIRED,
            $oper->refresh()->system_status,
        );
        $this->assertSame('Manual top up required for OPER account.', $oper->last_error_message);
        $this->assertDatabaseCount('gl_to_gl_transactions', 0);
        $this->assertDatabaseCount('early_termination_transactions', 0);
        Http::assertNothingSent();
    }

    public function test_failed_balance_inquiry_blocks_early_termination(): void
    {
        $receivable = $this->receivable();
        Http::fake([
            'http://core.test/account/balance*' => Http::response([
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
        Http::assertSentCount(1);
    }

    public function test_sufficient_decimal_balance_skips_gl_and_executes_early_termination(): void
    {
        $receivable = $this->receivable(['loan_outstanding' => '1000.10']);
        Http::fake([
            'http://core.test/account/balance*' => Http::response($this->balanceResponse('1000.100')),
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
        Http::assertSentCount(2);
    }

    public function test_positive_shortage_executes_exact_distribus_idapem_payload_then_early_termination(): void
    {
        $receivable = $this->receivable(['loan_outstanding' => '1000.105']);
        Http::fake([
            'http://core.test/account/balance*' => Http::response($this->balanceResponse('400.00')),
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
        $this->assertSame(GlToGlTransaction::PURPOSE_EARLY_TERMINATION_REPAYMENT_TOP_UP, $transaction->purpose);
        $this->assertSame($receivable->id, $transaction->insurance_receivable_id);
        $this->assertSame("ETTOP{$transaction->id}", $transaction->reference_number);
        $this->assertSame($transaction->reference_number, $transaction->receipt_number);
        $this->assertSame('DISTRIBUSIDAPEM', $transaction->request_payload['trxType']);
        $this->assertSame('', $transaction->request_payload['debitAccount']);
        $this->assertSame('', $transaction->request_payload['creditAccount']);
        $this->assertSame('1000010000000691', $transaction->request_payload['destAccount']);
        $this->assertSame('001', $transaction->request_payload['branchCode']);
        $this->assertSame('600.11', $transaction->request_payload['amount']);
        $this->assertSame(GlToGlTransaction::STATUS_SUCCESS, $transaction->status);
    }

    public function test_failed_top_up_retry_reuses_reference_and_immutable_payload(): void
    {
        $receivable = $this->receivable(['loan_outstanding' => '1000.00']);
        $user = $this->accountingApprover();
        Http::fake([
            'http://core.test/account/balance*' => Http::sequence()
                ->push($this->balanceResponse('0'))
                ->push($this->balanceResponse('400.00')),
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
        $firstReference = $first->reference_number;
        $firstPayload = $first->request_payload;

        $result = app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)->handle($receivable->refresh(), $user);
        $retried = GlToGlTransaction::query()->sole();

        $this->assertSame(
            EarlyTerminationTransaction::STATUS_SUCCESS,
            $result?->status,
            $receivable->refresh()->last_error_message ?? '',
        );
        $this->assertSame($first->id, $retried->id);
        $this->assertSame($firstReference, $retried->reference_number);
        $this->assertSame($firstPayload, $retried->request_payload);
        $this->assertSame('1000.00', $retried->request_payload['amount']);
        $this->assertSame(2, ApiIntegrationLog::query()->where('endpoint', '/trx/transfer/gl-to-gl')->count());
    }

    public function test_sufficient_balance_after_failed_top_up_skips_resend_and_executes_early_termination(): void
    {
        $receivable = $this->receivable(['loan_outstanding' => '1000.00']);
        $user = $this->accountingApprover();
        Http::fake([
            'http://core.test/account/balance*' => Http::sequence()
                ->push($this->balanceResponse('0'))
                ->push($this->balanceResponse('1000.00')),
            'http://core.test/trx/transfer/gl-to-gl' => Http::response(['unexpected' => 'unknown']),
            'http://core.test/loan/earlytermination/' => Http::response($this->earlyTerminationSuccess()),
        ]);
        app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)->handle($receivable, $user);

        $result = app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)->handle($receivable->refresh(), $user);

        $this->assertSame(
            EarlyTerminationTransaction::STATUS_SUCCESS,
            $result?->status,
            $receivable->refresh()->last_error_message ?? '',
        );
        $this->assertSame(1, ApiIntegrationLog::query()->where('endpoint', '/trx/transfer/gl-to-gl')->count());
        $this->assertSame(GlToGlTransaction::STATUS_FAILED, GlToGlTransaction::query()->sole()->status);
    }

    public function test_transport_timeout_retries_the_same_top_up_reference(): void
    {
        $receivable = $this->receivable(['loan_outstanding' => '1000.00']);
        $user = $this->accountingApprover();
        $glAttempts = 0;
        Http::fake(function (Request $request) use (&$glAttempts) {
            if (str_contains($request->url(), '/account/balance')) {
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

        $result = app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)->handle($receivable->refresh(), $user);
        $retried = GlToGlTransaction::query()->sole();

        $this->assertSame(EarlyTerminationTransaction::STATUS_SUCCESS, $result?->status);
        $this->assertSame($first->id, $retried->id);
        $this->assertSame($reference, $retried->reference_number);
        $this->assertSame(2, ApiIntegrationLog::query()->where('endpoint', '/trx/transfer/gl-to-gl')->count());
    }

    public function test_successful_top_up_is_not_repeated_when_early_termination_retries(): void
    {
        $receivable = $this->receivable(['loan_outstanding' => '1000.00']);
        Http::fake([
            'http://core.test/account/balance*' => Http::response($this->balanceResponse('0')),
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
        $this->assertSame(1, ApiIntegrationLog::query()->where('endpoint', '/account/balance')->count());
        $this->assertSame(1, ApiIntegrationLog::query()->where('endpoint', '/trx/transfer/gl-to-gl')->count());
    }

    public function test_manual_confirmation_skips_balance_and_gl_and_is_authorized_in_ui(): void
    {
        $receivable = $this->receivable([
            'saving_account_for_loan_repayment' => 'OPER-01',
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_TOP_UP_REQUIRED,
            'last_error_message' => 'Manual top up required for OPER account.',
        ]);
        $approver = $this->accountingApprover();
        Http::fake([
            'http://core.test/loan/earlytermination/' => Http::response($this->earlyTerminationSuccess()),
        ]);

        $result = app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)
            ->handle($receivable, $approver, true);

        $this->assertSame(EarlyTerminationTransaction::STATUS_SUCCESS, $result?->status);
        $this->assertSame(1, ApiIntegrationLog::query()->where('endpoint', '/loan/earlytermination/')->count());
        $this->assertSame(0, ApiIntegrationLog::query()->where('endpoint', '/account/balance')->count());
        $this->assertDatabaseCount('gl_to_gl_transactions', 0);

        Queue::fake();
        $receivable->forceFill([
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_TOP_UP_REQUIRED,
        ])->saveQuietly();
        Livewire::actingAs($approver)
            ->test(ViewInsuranceReceivable::class, ['record' => $receivable->id])
            ->assertActionVisible('confirmManualTopUpAndExecuteEarlyTermination')
            ->callAction('confirmManualTopUpAndExecuteEarlyTermination');
        Queue::assertPushed(ExecuteEarlyTerminationJob::class, fn (ExecuteEarlyTerminationJob $job): bool => $job->manualTopUpConfirmed);

        $unauthorized = $this->userWithRole('accounting_maker', '000');
        $receivable->forceFill([
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_TOP_UP_REQUIRED,
        ])->saveQuietly();
        Livewire::actingAs($unauthorized)
            ->test(ViewInsuranceReceivable::class, ['record' => $receivable->id])
            ->assertActionHidden('confirmManualTopUpAndExecuteEarlyTermination');
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

        return InsuranceReceivable::factory()->create([
            'branch_office_id' => $branch->id,
            'branch_code' => $branch->branch_code,
            'loan_account_number' => '3010010000000068',
            'saving_account_for_loan_repayment' => '1000010000000691',
            'loan_outstanding' => '1000.00',
            'receivable_amount' => '1000.00',
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
