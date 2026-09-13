<?php

namespace Tests\Feature;

use App\Actions\InsuranceReceivable\ExecuteEarlyTerminationWithRepaymentTopUpAction;
use App\Models\BranchOffice;
use App\Models\EarlyTerminationBalanceInquiry;
use App\Models\EarlyTerminationTransaction;
use App\Models\GlToGlTransaction;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\InsuranceReceivable\CalculateEarlyTerminationSplitTopUp;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class EarlyTerminationSplitTopUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'core_banking.base_url' => 'http://core.test',
            'core_banking.signature_secret' => 'secret-key',
        ]);
        Carbon::setTestNow('2026-07-13 10:20:30');
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

    public function test_split_et_executes_lsa_before_piutang_and_final_verification(): void
    {
        $receivable = $this->receivable([
            'loan_outstanding' => '100.00',
            'contract_outstanding_amount' => '93.00',
            'receivable_amount' => '93.00',
            'remaining_receivable_amount' => '93.00',
        ]);
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::sequence()
                ->push($this->loanResponse('100.00'))
                ->push($this->loanResponse('100.00')),
            'http://core.test/saving/inq/balance*' => Http::sequence()
                ->push($this->balanceResponse('50.00'))
                ->push($this->balanceResponse('150.00')),
            'http://core.test/trx/transfer/gl-to-gl' => Http::sequence()
                ->push($this->successResponse('LSA OK'))
                ->push($this->successResponse('PIUTANG OK')),
            'http://core.test/loan/earlytermination/' => Http::response($this->earlyTerminationSuccess()),
        ]);

        $result = app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)
            ->handle($receivable, $this->accountingApprover());

        $this->assertSame(EarlyTerminationTransaction::STATUS_SUCCESS, $result?->status);

        $lsa = GlToGlTransaction::query()
            ->where('purpose', GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP)
            ->sole();
        $piutang = GlToGlTransaction::query()
            ->where('purpose', GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP)
            ->sole();

        $this->assertSame('ir:'.$receivable->id.':et:flat-spread', $lsa->idempotency_key);
        $this->assertSame('ETLSA'.$receivable->id.'001', $lsa->reference_number);
        $this->assertSame('LSA01', $lsa->request_payload['trxType']);
        $this->assertSame('7.00', $lsa->request_payload['amount']);
        $this->assertSame('001000OPER', $lsa->request_payload['destAccount']);
        $this->assertSame('ir:'.$receivable->id.':et:contract', $piutang->idempotency_key);
        $this->assertSame('ETPIU'.$receivable->id.'001', $piutang->reference_number);
        $this->assertSame('PiutangAsuransi', $piutang->request_payload['trxType']);
        $this->assertSame('93.00', $piutang->request_payload['amount']);
        $this->assertSame('001000OPER', $piutang->request_payload['destAccount']);
        $this->assertSame([
            EarlyTerminationBalanceInquiry::CONTEXT_PRE_TOP_UP,
            EarlyTerminationBalanceInquiry::CONTEXT_POST_TOP_UP_VERIFICATION,
        ], EarlyTerminationBalanceInquiry::query()->orderBy('id')->pluck('context')->all());
    }

    public function test_existing_oper_balance_never_changes_fixed_funding_components(): void
    {
        $receivable = $this->receivable([
            'loan_outstanding' => '100.00',
            'contract_outstanding_amount' => '93.00',
            'receivable_amount' => '93.00',
            'remaining_receivable_amount' => '93.00',
        ]);
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse('100.00')),
            'http://core.test/saving/inq/balance*' => Http::sequence()
                ->push($this->balanceResponse('500.00'))
                ->push($this->balanceResponse('600.00')),
            'http://core.test/trx/transfer/gl-to-gl' => Http::response($this->successResponse('OK')),
            'http://core.test/loan/earlytermination/' => Http::response($this->earlyTerminationSuccess()),
        ]);

        app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)
            ->handle($receivable, $this->accountingApprover());

        $this->assertSame('7.00', GlToGlTransaction::query()
            ->where('purpose', GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP)
            ->sole()->request_payload['amount']);
        $this->assertSame('93.00', GlToGlTransaction::query()
            ->where('purpose', GlToGlTransaction::PURPOSE_EARLY_TERMINATION_CONTRACT_TOP_UP)
            ->sole()->request_payload['amount']);
    }

    public function test_fixed_funding_calculator_validates_contract_bounds_and_zero_spread(): void
    {
        $calculator = app(CalculateEarlyTerminationSplitTopUp::class);
        $equal = $calculator->handle('100.00', '100.00');

        $this->assertSame('0.00', (string) $equal->lsaTopUpAmount);
        $this->assertSame('100.00', (string) $equal->piutangTopUpAmount);

        foreach ([['100.00', '101.00'], ['100.00', '0'], ['100.00', '-1']] as [$fincloud, $contract]) {
            try {
                $calculator->handle($fincloud, $contract);
                $this->fail('Invalid Contract Outstanding must fail.');
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function test_oper_lock_blocks_simultaneous_orchestration_for_same_account(): void
    {
        $lock = Cache::lock('insurance-receivable:oper:001000OPER:early-termination', 300);
        $this->assertTrue($lock->get());
        Http::fake();

        try {
            $this->expectException(ValidationException::class);
            app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)
                ->handle($this->receivable(), $this->accountingApprover());
        } finally {
            $lock->release();
        }
    }

    public function test_different_oper_accounts_use_independent_locks(): void
    {
        $first = Cache::lock('insurance-receivable:oper:001000OPER:early-termination', 900);
        $second = Cache::lock('insurance-receivable:oper:123000OPER:early-termination', 900);

        $this->assertTrue($first->get());

        try {
            $this->assertTrue($second->get());
        } finally {
            $second->release();
            $first->release();
        }
    }

    public function test_final_fresh_loan_mismatch_blocks_et_api(): void
    {
        $receivable = $this->receivable();
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::sequence()
                ->push($this->loanResponse('1000.00'))
                ->push($this->loanResponse('1100.00')),
            'http://core.test/saving/inq/balance*' => Http::response($this->balanceResponse('1000.00')),
            'http://core.test/trx/transfer/gl-to-gl' => Http::response($this->successResponse('OK')),
            'http://core.test/loan/earlytermination/' => Http::response($this->earlyTerminationSuccess()),
        ]);

        $result = app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)
            ->handle($receivable, $this->accountingApprover());

        $this->assertNull($result);
        $this->assertSame(
            InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_TOP_UP_FAILED,
            $receivable->refresh()->system_status,
        );
        $this->assertStringContainsString('loan outstanding changed', $receivable->last_error_message);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/loan/earlytermination/'));
    }

    public function test_insufficient_post_funding_oper_balance_blocks_et(): void
    {
        $receivable = $this->receivable([
            'loan_outstanding' => '100.00',
            'contract_outstanding_amount' => '93.00',
            'receivable_amount' => '93.00',
            'remaining_receivable_amount' => '93.00',
        ]);
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse('100.00')),
            'http://core.test/saving/inq/balance*' => Http::sequence()
                ->push($this->balanceResponse('0'))
                ->push($this->balanceResponse('99.99')),
            'http://core.test/trx/transfer/gl-to-gl' => Http::response($this->successResponse('OK')),
            'http://core.test/loan/earlytermination/' => Http::response($this->earlyTerminationSuccess()),
        ]);

        $result = app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)
            ->handle($receivable, $this->accountingApprover());

        $this->assertNull($result);
        $this->assertStringContainsString('below funded Fincloud outstanding', $receivable->refresh()->last_error_message);
        $this->assertSame(['7.00', '93.00'], GlToGlTransaction::query()
            ->orderBy('id')->get()->pluck('request_payload')->map(fn (array $payload): string => $payload['amount'])->all());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/loan/earlytermination/'));
    }

    public function test_changed_required_amount_blocks_reuse_of_satisfied_immutable_component(): void
    {
        $receivable = $this->receivable();
        GlToGlTransaction::query()->create([
            'purpose' => GlToGlTransaction::PURPOSE_EARLY_TERMINATION_FLAT_SPREAD_TOP_UP,
            'insurance_receivable_id' => $receivable->id,
            'reference_number' => 'ETLSA-OLD',
            'receipt_number' => 'ETLSA-OLD',
            'request_payload' => ['amount' => '50.00'],
            'status' => GlToGlTransaction::STATUS_SUCCESS,
        ]);
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse('1000.00')),
            'http://core.test/saving/inq/balance*' => Http::response($this->balanceResponse('1000.00')),
        ]);

        $result = app(ExecuteEarlyTerminationWithRepaymentTopUpAction::class)
            ->handle($receivable, $this->accountingApprover());

        $transaction = GlToGlTransaction::query()->sole();
        $this->assertNull($result);
        $this->assertSame(GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED, $transaction->resolution_status);
        $this->assertDatabaseCount('gl_to_gl_transactions', 1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/trx/transfer/gl-to-gl'));
    }

    private function receivable(array $attributes = []): InsuranceReceivable
    {
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();

        return InsuranceReceivable::factory()->create([
            'branch_office_id' => $branch->id,
            'branch_code' => '001',
            'loan_account_number' => '3000010000000113',
            'alt_number' => 'ALT-1',
            'saving_account_for_loan_repayment' => '001000OPER',
            'loan_outstanding' => '1000.00',
            'contract_outstanding_amount' => '900.00',
            'contract_outstanding_requested_as_of' => '2026-06-30',
            'contract_outstanding_as_of' => '2026-06-30',
            'contract_outstanding_product_code' => '301',
            'contract_outstanding_trx_type' => 'LSA01',
            'receivable_amount' => '900.00',
            'remaining_receivable_amount' => '900.00',
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_PROCESSING,
            ...$attributes,
        ]);
    }

    private function accountingApprover(): User
    {
        $branch = BranchOffice::query()->where('branch_code', '000')->firstOrFail();
        $user = User::factory()->create(['branch_office_id' => $branch->id]);
        $user->assignRole('accounting_approver');

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
                'loanOutStanding' => $outstanding,
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

    private function successResponse(string $description): array
    {
        return [
            'responseCode' => '00',
            'description' => $description,
            'data' => [],
        ];
    }

    private function earlyTerminationSuccess(): array
    {
        return [
            'responseCode' => '00',
            'description' => 'Success',
            'data' => ['transactionId' => 'ET-TRX-1'],
        ];
    }
}
