<?php

namespace Tests\Feature;

use App\Actions\InsuranceReceivable\ApproveInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\ResolveInstallmentRepaymentAction;
use App\Actions\InsuranceReceivable\RetryInstallmentRepaymentAction;
use App\Models\ApiIntegrationLog;
use App\Models\ApprovalRequest;
use App\Models\BranchOffice;
use App\Models\InsuranceReceivable;
use App\Models\InsuranceReceivableInstallmentRepayment;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountingValidationInstallmentRepaymentTest extends TestCase
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

        $this->seed([
            BranchOfficeSeeder::class,
            InsuranceCompanySeeder::class,
            ClaimStatusSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    public function test_equal_or_later_death_date_does_not_repay(): void
    {
        foreach (['2026-06-15', '2026-06-16'] as $deathDate) {
            [$receivable, $approver] = $this->accountingValidationReceivable(['date_of_death' => $deathDate]);
            Http::fake([
                'http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse([
                    'accountNumber' => $receivable->loan_account_number,
                    'nextDueDate' => '20260615',
                    'loanOutStanding' => '9000.00',
                ])),
                'http://contract.test/api/slik/inquiry' => Http::response($this->contractResponse('9000')),
            ]);

            $result = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $approver);

            $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED, $result->workflow_status);
            $this->assertSame('9000.00', $result->receivable_amount);
            $this->assertDatabaseCount('insurance_receivable_installment_repayments', 0);
            Http::assertSentCount(3);
        }
    }

    public function test_required_repayment_posts_payload_verifies_lower_outstanding_and_completes_approval(): void
    {
        [$receivable, $approver] = $this->accountingValidationReceivable(['date_of_death' => '2026-06-14']);
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::sequence()
                ->push($this->loanResponse([
                    'accountNumber' => $receivable->loan_account_number,
                    'loanOutStanding' => '10000.00',
                    'installmentAmount' => '1500.00',
                    'nextDueDate' => '20260615',
                ]))
                ->push($this->loanResponse([
                    'accountNumber' => $receivable->loan_account_number,
                    'loanOutStanding' => '9500.00',
                    'installmentAmount' => '1500.00',
                    'nextDueDate' => '20260615',
                ]))
                ->push($this->loanResponse([
                    'accountNumber' => $receivable->loan_account_number,
                    'loanOutStanding' => '9500.00',
                    'installmentAmount' => '1500.00',
                    'nextDueDate' => '20260615',
                ])),
            'http://core.test/saving/inq/balance*' => Http::response($this->balanceResponse('2000.00')),
            'http://core.test/loan/repayment/' => Http::response($this->successResponse()),
            'http://contract.test/api/slik/inquiry' => Http::response($this->contractResponse('9500')),
        ]);

        $result = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $approver);
        $repayment = InsuranceReceivableInstallmentRepayment::query()->sole();

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED, $result->workflow_status);
        $this->assertSame(InsuranceReceivableInstallmentRepayment::STATUS_EXECUTED, $repayment->status);
        $this->assertSame('10000.00', $repayment->loan_outstanding_before);
        $this->assertSame('9500.00', $repayment->loan_outstanding_after);
        $this->assertSame('9500.00', $result->receivable_amount);
        $this->assertSame(6, ApiIntegrationLog::query()->count());
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://core.test/loan/repayment/'
            && str_starts_with((string) (json_decode($request->body(), true)['trxReference'] ?? ''), 'IRREP')
            && json_decode($request->body(), true)['accountNumber'] === $receivable->loan_account_number
            && json_decode($request->body(), true)['altNumber'] === 'ALT-1'
            && json_decode($request->body(), true)['paymentAmount'] === '1500.00'
            && json_decode($request->body(), true)['branchCode'] === '001');
    }

    public function test_already_executed_repayment_reuses_contract_snapshot_and_revalidates_fincloud(): void
    {
        [$receivable, $approver] = $this->accountingValidationReceivable([
            'date_of_death' => '2026-06-14',
            'loan_outstanding' => '10000.00',
            'contract_outstanding_amount' => '9500.00',
            'contract_outstanding_requested_as_of' => '2026-06-30',
            'contract_outstanding_as_of' => '2026-06-30',
            'contract_outstanding_product_code' => '301',
            'contract_outstanding_trx_type' => 'LSA01',
        ]);
        InsuranceReceivableInstallmentRepayment::query()->create([
            'insurance_receivable_id' => $receivable->id,
            'account_number' => $receivable->loan_account_number,
            'alt_number' => 'ALT-1',
            'branch_code' => '001',
            'saving_account_number' => '001000OPER',
            'installment_amount' => '1500.00',
            'loan_outstanding_before' => '10000.00',
            'loan_outstanding_after' => '9500.00',
            'next_due_date' => '2026-06-15',
            'date_of_death' => '2026-06-14',
            'status' => InsuranceReceivableInstallmentRepayment::STATUS_EXECUTED,
        ]);
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse([
                'accountNumber' => $receivable->loan_account_number,
                'loanOutStanding' => '9500.00',
                'nextDueDate' => '20260615',
            ])),
            'http://contract.test/api/slik/inquiry' => Http::response(['should_not' => 'call']),
        ]);

        $result = app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $approver);

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED, $result->workflow_status);
        $this->assertSame('9500.00', $result->receivable_amount);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'contract.test'));
    }

    public function test_invalid_oper_blocks_before_installment_repayment_side_effects(): void
    {
        [$receivable, $approver] = $this->accountingValidationReceivable(['date_of_death' => '2026-06-14']);
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse([
                'accountNumber' => $receivable->loan_account_number,
                'saForLoanRepayment' => '',
                'nextDueDate' => '20260615',
            ])),
        ]);

        try {
            app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $approver);
            $this->fail('Repayment validation should block approval.');
        } catch (ValidationException) {
        }

        $this->assertDatabaseCount('insurance_receivable_installment_repayments', 0);
        $this->assertSame(ApprovalRequest::STATUS_SUBMITTED, ApprovalRequest::query()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION)
            ->sole()
            ->status);
        Http::assertSentCount(1);
    }

    public function test_successful_post_with_unchanged_outstanding_requires_resolve_not_retry(): void
    {
        [$receivable, $approver] = $this->accountingValidationReceivable(['date_of_death' => '2026-06-14']);
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::sequence()
                ->push($this->loanResponse([
                    'accountNumber' => $receivable->loan_account_number,
                    'loanOutStanding' => '10000.00',
                    'nextDueDate' => '20260615',
                ]))
                ->push($this->loanResponse([
                    'accountNumber' => $receivable->loan_account_number,
                    'loanOutStanding' => '10000.00',
                    'nextDueDate' => '20260615',
                ])),
            'http://core.test/saving/inq/balance*' => Http::response($this->balanceResponse('2000.00')),
            'http://core.test/loan/repayment/' => Http::response($this->successResponse()),
        ]);

        try {
            app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $approver);
            $this->fail('Verification failure should block approval.');
        } catch (ValidationException) {
        }

        $repayment = InsuranceReceivableInstallmentRepayment::query()->sole();
        $this->assertSame(InsuranceReceivableInstallmentRepayment::STATUS_VERIFICATION_FAILED_AFTER_EXECUTION, $repayment->status);
        $this->assertFalse($repayment->canRetry());

        $this->expectException(ValidationException::class);
        app(RetryInstallmentRepaymentAction::class)->handle($receivable->refresh(), $approver);
    }

    public function test_resolve_verifies_decreased_outstanding_and_completes_pending_approval(): void
    {
        [$receivable, $approver] = $this->accountingValidationReceivable(['date_of_death' => '2026-06-14']);
        $repayment = InsuranceReceivableInstallmentRepayment::query()->create([
            'insurance_receivable_id' => $receivable->id,
            'account_number' => $receivable->loan_account_number,
            'alt_number' => 'ALT-1',
            'branch_code' => '001',
            'saving_account_number' => '001000OPER',
            'installment_amount' => '1500.00',
            'loan_outstanding_before' => '10000.00',
            'next_due_date' => '2026-06-15',
            'date_of_death' => '2026-06-14',
            'status' => InsuranceReceivableInstallmentRepayment::STATUS_VERIFICATION_FAILED_AFTER_EXECUTION,
        ]);
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::sequence()
                ->push($this->loanResponse([
                    'accountNumber' => $receivable->loan_account_number,
                    'loanOutStanding' => '9900.00',
                    'nextDueDate' => '20260615',
                ]))
                ->push($this->loanResponse([
                    'accountNumber' => $receivable->loan_account_number,
                    'loanOutStanding' => '9900.00',
                    'nextDueDate' => '20260615',
                ])),
            'http://contract.test/api/slik/inquiry' => Http::response($this->contractResponse('9900')),
        ]);

        $result = app(ResolveInstallmentRepaymentAction::class)->handle($receivable, $approver);

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED, $result->workflow_status);
        $this->assertSame(InsuranceReceivableInstallmentRepayment::STATUS_RESOLVED_MANUALLY, $repayment->refresh()->status);
        $this->assertSame('9900.00', $result->receivable_amount);
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, ApprovalRequest::query()
            ->where('workflow_code', ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION)
            ->sole()
            ->status);
    }

    public function test_timeout_unknown_state_blocks_retry(): void
    {
        [$receivable, $approver] = $this->accountingValidationReceivable(['date_of_death' => '2026-06-14']);
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response($this->loanResponse([
                'accountNumber' => $receivable->loan_account_number,
                'loanOutStanding' => '10000.00',
                'nextDueDate' => '20260615',
            ])),
            'http://core.test/saving/inq/balance*' => Http::response($this->balanceResponse('2000.00')),
            'http://core.test/loan/repayment/' => fn () => throw new ConnectionException('Connection timed out.'),
        ]);

        try {
            app(ApproveInsuranceReceivableApprovalAction::class)->handle($receivable, $approver);
            $this->fail('Unknown repayment result should block approval.');
        } catch (ValidationException) {
        }

        $repayment = InsuranceReceivableInstallmentRepayment::query()->sole();
        $this->assertSame(InsuranceReceivableInstallmentRepayment::STATUS_UNKNOWN_TIMEOUT, $repayment->status);
        $this->assertFalse($repayment->canRetry());
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
            'loan_account_number' => '3010000000000001',
            'saving_account_for_loan_repayment' => '001000OPER',
            'collectability' => '5',
            'loan_outstanding' => '10000.00',
            'date_of_death' => '2026-06-14',
            ...$attributes,
        ]);

        app(ApprovalService::class)->submit(
            $receivable,
            ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION,
            $it,
        );

        return [$receivable, $approver];
    }

    private function userWithRole(string $role, string $branchCode): User
    {
        $branchOffice = BranchOffice::query()->where('branch_code', $branchCode)->firstOrFail();
        $user = User::factory()->create(['branch_office_id' => $branchOffice->id]);
        $user->assignRole($role);

        return $user;
    }

    private function loanResponse(array $overrides = []): array
    {
        return [
            'responseCode' => '00',
            'description' => 'SUCCESS',
            'data' => [
                'accountNumber' => '3010000000000001',
                'altNumber' => 'ALT-1',
                'branchCode' => '001',
                'collectability' => '5',
                'dpd' => 0,
                'saForLoanRepayment' => '001000OPER',
                'loanOutStanding' => '10000.00',
                'installmentAmount' => '1500.00',
                'nextDueDate' => '20260615',
                ...$overrides,
            ],
        ];
    }

    private function balanceResponse(string $availableBalance, string $documentStatus = 'Active'): array
    {
        return [
            'responseCode' => '00',
            'description' => 'SUCCESS',
            'data' => [
                'accountNumber' => '001000OPER',
                'documentStatus' => $documentStatus,
                'availableBalance' => $availableBalance,
                'ledgerBalance' => $availableBalance,
            ],
        ];
    }

    private function contractResponse(string $bakiDebet): array
    {
        return [
            'result' => [
                'AccountNumber' => '3010000000000001',
                'AsOf' => '2026-06-30T00:00:00Z',
                'BakiDebet' => $bakiDebet,
            ],
            'loan' => [
                'AccountNumber' => '3010000000000001',
                'Product' => '301 - Kredit Pegawai Aktif',
            ],
        ];
    }

    private function successResponse(): array
    {
        return [
            'responseCode' => '00',
            'description' => 'SUCCESS',
            'data' => ['posted' => true],
        ];
    }
}
