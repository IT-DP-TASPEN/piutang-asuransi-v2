<?php

namespace Tests\Feature;

use App\Actions\InsuranceReceivable\ExecuteEarlyTerminationWithRepaymentTopUpAction;
use App\Actions\InsuranceReceivable\ResolveEarlyTerminationTopUpReconciliationAction;
use App\Models\ApiIntegrationLog;
use App\Models\BranchOffice;
use App\Models\EarlyTerminationTransaction;
use App\Models\GlToGlTransaction;
use App\Models\InsuranceReceivable;
use App\Models\User;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EarlyTerminationTopUpReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'core_banking.base_url' => 'http://core.test',
            'core_banking.signature_secret' => 'secret-key',
        ]);
        Carbon::setTestNow('2026-09-13 10:20:30');
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

    public function test_high_pooled_oper_balance_does_not_resolve_ambiguous_lsa(): void
    {
        $receivable = $this->receivable();
        $user = $this->accountingApprover();
        $glCalls = 0;
        Http::fake(function (Request $request) use (&$glCalls) {
            if (str_contains($request->url(), '/inquiry/detail/loan')) {
                return Http::response($this->loanResponse());
            }

            if (str_contains($request->url(), '/saving/inq/balance')) {
                return Http::response($this->balanceResponse('500.00'));
            }

            if (str_contains($request->url(), '/trx/transfer/gl-to-gl')) {
                $glCalls++;
                throw new ConnectionException('Connection timed out.');
            }

            return Http::response($this->earlyTerminationSuccess());
        });

        $this->assertNull(app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)->handle($receivable, $user));
        $transaction = GlToGlTransaction::query()->sole();

        $this->assertSame(GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP, $transaction->purpose);
        $this->assertSame(GlToGlTransaction::STATUS_UNKNOWN_TIMEOUT, $transaction->status);
        $this->assertSame(GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED, $transaction->resolution_status);
        $this->assertSame(GlToGlTransaction::RESOLUTION_OUTCOME_STILL_UNKNOWN, $transaction->resolution_outcome);

        $this->assertNull(app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)->handle($receivable->refresh(), $user));
        $this->assertSame(1, $glCalls);
        $this->assertDatabaseCount('gl_to_gl_transactions', 1);
        $this->assertDatabaseCount('early_termination_transactions', 0);
    }

    public function test_high_pooled_oper_balance_does_not_resolve_ambiguous_piutang(): void
    {
        [$receivable, $user, $glCalls] = $this->createAmbiguousPiutang();
        $transaction = GlToGlTransaction::query()
            ->where('purpose', GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP)
            ->sole();

        $this->assertSame(2, $glCalls());
        $this->assertSame(GlToGlTransaction::STATUS_UNKNOWN_TIMEOUT, $transaction->status);
        $this->assertSame(GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED, $transaction->resolution_status);
        $this->assertSame(GlToGlTransaction::RESOLUTION_OUTCOME_STILL_UNKNOWN, $transaction->resolution_outcome);

        $this->assertNull(app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)->handle($receivable->refresh(), $user));
        $this->assertSame(2, $glCalls());
        $this->assertDatabaseCount('gl_to_gl_transactions', 2);
        $this->assertDatabaseCount('early_termination_transactions', 0);
    }

    public function test_manual_posted_resolution_records_audit_and_allows_et_without_duplicate_transfer(): void
    {
        [$receivable, $user] = $this->createAmbiguousPiutang();
        $apiLogCount = ApiIntegrationLog::query()->count();
        Http::fake();

        $resolved = app(ResolveEarlyTerminationTopUpReconciliationAction::class)->handle(
            $receivable->refresh(),
            GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP,
            GlToGlTransaction::RESOLUTION_OUTCOME_POSTED,
            $user,
            'Confirmed exact reference in Core journal.',
        );

        $this->assertSame(GlToGlTransaction::RESOLUTION_STATUS_RESOLVED_MANUALLY, $resolved->resolution_status);
        $this->assertSame(GlToGlTransaction::RESOLUTION_OUTCOME_POSTED, $resolved->resolution_outcome);
        $this->assertSame($user->id, $resolved->resolved_by);
        $this->assertSame('2026-09-13 10:20:30', $resolved->resolved_at?->toDateTimeString());
        $this->assertSame('Confirmed exact reference in Core journal.', $resolved->resolution_notes);
        $this->assertSame($resolved->id, $resolved->resolution_payload['gl_to_gl_transaction_id']);
        $this->assertSame($resolved->reference_number, $resolved->resolution_payload['reference_number']);
        $this->assertSame('93.00', $resolved->resolution_payload['amount']);
        $this->assertSame($apiLogCount, ApiIntegrationLog::query()->count());

        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse()),
            'http://core.test/saving/inq/balance*' => Http::response($this->balanceResponse('500.00')),
            'http://core.test/loan/earlytermination/' => Http::response($this->earlyTerminationSuccess()),
        ]);

        $result = app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)->handle($receivable->refresh(), $user);

        $this->assertSame(EarlyTerminationTransaction::STATUS_SUCCESS, $result?->status);
        $this->assertDatabaseCount('gl_to_gl_transactions', 2);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/trx/transfer/gl-to-gl'));
    }

    public function test_manual_not_posted_resolution_allows_one_safe_retry_with_new_reference(): void
    {
        [$receivable, $user] = $this->createAmbiguousPiutang();
        $first = GlToGlTransaction::query()
            ->where('purpose', GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP)
            ->sole();

        $resolved = app(ResolveEarlyTerminationTopUpReconciliationAction::class)->handle(
            $receivable->refresh(),
            GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP,
            GlToGlTransaction::RESOLUTION_OUTCOME_NOT_POSTED,
            $user,
            'Confirmed exact reference absent from Core journal.',
        );

        $this->assertTrue($resolved->canRetry());
        $this->assertSame(GlToGlTransaction::STATUS_UNKNOWN_TIMEOUT, $resolved->status);
        $this->assertSame(GlToGlTransaction::RESOLUTION_OUTCOME_NOT_POSTED, $resolved->resolution_outcome);

        $retryGlCalls = 0;
        Http::fake(function (Request $request) use (&$retryGlCalls) {
            if (str_contains($request->url(), '/inquiry/detail/loan')) {
                return Http::response($this->loanResponse());
            }

            if (str_contains($request->url(), '/saving/inq/balance')) {
                return Http::response($this->balanceResponse('500.00'));
            }

            if (str_contains($request->url(), '/trx/transfer/gl-to-gl')) {
                $retryGlCalls++;

                return Http::response($this->successResponse());
            }

            return Http::response($this->earlyTerminationSuccess());
        });

        $result = app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)->handle($receivable->refresh(), $user);
        $attempts = GlToGlTransaction::query()
            ->where('purpose', GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP)
            ->orderBy('attempt_no')
            ->get();

        $this->assertSame(EarlyTerminationTransaction::STATUS_SUCCESS, $result?->status);
        $this->assertCount(2, $attempts);
        $this->assertSame($first->reference_number, $attempts[0]->reference_number);
        $this->assertSame("ETPIU{$receivable->id}002", $attempts[1]->reference_number);
        $this->assertSame('93.00', $attempts[1]->request_payload['amount']);
        $this->assertDatabaseCount('gl_to_gl_transactions', 3);
        $this->assertSame(1, $retryGlCalls);
    }

    public function test_manual_reconciliation_requires_note_and_valid_outcome(): void
    {
        [$receivable, $user] = $this->createAmbiguousPiutang();
        $action = app(ResolveEarlyTerminationTopUpReconciliationAction::class);

        try {
            $action->handle(
                $receivable->refresh(),
                GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP,
                GlToGlTransaction::RESOLUTION_OUTCOME_POSTED,
                $user,
            );
            $this->fail('Manual reconciliation note must be required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reconciliation', $exception->errors());
        }

        $this->expectException(ValidationException::class);
        $action->handle(
            $receivable->refresh(),
            GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP,
            GlToGlTransaction::RESOLUTION_OUTCOME_STILL_UNKNOWN,
            $user,
            'Unknown is not a resolution.',
        );
    }

    /** @return array{InsuranceReceivable, User, callable(): int} */
    private function createAmbiguousPiutang(): array
    {
        $receivable = $this->receivable();
        $user = $this->accountingApprover();
        $glCalls = 0;
        Http::fake(function (Request $request) use (&$glCalls) {
            if (str_contains($request->url(), '/inquiry/detail/loan')) {
                return Http::response($this->loanResponse());
            }

            if (str_contains($request->url(), '/saving/inq/balance')) {
                return Http::response($this->balanceResponse('500.00'));
            }

            if (str_contains($request->url(), '/trx/transfer/gl-to-gl')) {
                $glCalls++;

                if ($glCalls === 2) {
                    throw new ConnectionException('Connection timed out.');
                }

                return Http::response($this->successResponse());
            }

            return Http::response($this->earlyTerminationSuccess());
        });

        $this->assertNull(app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)->handle($receivable, $user));

        return [$receivable, $user, fn (): int => $glCalls];
    }

    private function receivable(): InsuranceReceivable
    {
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();

        return InsuranceReceivable::factory()->create([
            'branch_office_id' => $branch->id,
            'branch_code' => '001',
            'loan_account_number' => '3000010000000113',
            'alt_number' => 'ALT-1',
            'saving_account_for_loan_repayment' => '001000OPER',
            'loan_outstanding' => '100.00',
            'contract_outstanding_amount' => '93.00',
            'contract_outstanding_requested_as_of' => '2026-09-13',
            'contract_outstanding_as_of' => '2026-09-13',
            'contract_outstanding_product_code' => '301',
            'contract_outstanding_trx_type' => 'LSA01',
            'receivable_amount' => '93.00',
            'remaining_receivable_amount' => '93.00',
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_PROCESSING,
        ]);
    }

    private function accountingApprover(): User
    {
        $branch = BranchOffice::query()->where('branch_code', '000')->firstOrFail();
        $user = User::factory()->create(['branch_office_id' => $branch->id]);
        $user->assignRole('accounting_approver');

        return $user;
    }

    private function loanResponse(): array
    {
        return [
            'responseCode' => '00',
            'description' => 'SUCCESS',
            'data' => [
                'accountNumber' => '3000010000000113',
                'altNumber' => 'ALT-1',
                'branchCode' => '001',
                'collectability' => '5',
                'loanOutStanding' => '100.00',
                'saForLoanRepayment' => '001000OPER',
            ],
        ];
    }

    private function balanceResponse(string $available): array
    {
        return [
            'responseCode' => '00',
            'description' => 'SUCCESS',
            'data' => [
                'accountNumber' => '001000OPER',
                'availableBalance' => $available,
            ],
        ];
    }

    private function successResponse(): array
    {
        return ['responseCode' => '00', 'description' => 'SUCCESS', 'data' => []];
    }

    private function earlyTerminationSuccess(): array
    {
        return [
            'responseCode' => '00',
            'description' => 'SUCCESS',
            'data' => ['transactionId' => 'ET-TRX-1'],
        ];
    }
}
