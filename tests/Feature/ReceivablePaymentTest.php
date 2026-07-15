<?php

namespace Tests\Feature;

use App\Actions\ReceivablePayment\ExecuteReceivablePaymentRequestAction;
use App\Actions\ReceivablePayment\RecordReceivablePaymentAction;
use App\Actions\ReceivablePayment\SubmitReceivablePaymentRequestAction;
use App\Filament\Resources\InsuranceReceivables\InsuranceReceivableResource;
use App\Filament\Resources\InsuranceReceivables\Pages\ViewInsuranceReceivable;
use App\Filament\Resources\RelationManagers\ReceivablePaymentsRelationManager;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\BranchOffice;
use App\Models\CoreTransactionReference;
use App\Models\GlToGlTransaction;
use App\Models\InsuranceReceivable;
use App\Models\ReceivablePayment;
use App\Models\ReceivablePaymentRequest;
use App\Models\User;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ReceivablePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'core_banking.base_url' => 'http://core.test',
            'core_banking.signature_secret' => 'secret-key',
        ]);
        Carbon::setTestNow('2026-07-07 10:20:30');
        $this->seedDependencies();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_maker_current_account_submit_creates_request_and_pending_approval_only(): void
    {
        Http::fake();
        $maker = $this->userWithRole('accounting_maker', '000');
        $receivable = $this->receivable();

        $request = app(SubmitReceivablePaymentRequestAction::class)->handle($receivable, [
            'amount' => '2500.00',
            'payment_source' => ReceivablePaymentRequest::PAYMENT_SOURCE_CURRENT_ACCOUNT_MANDIRI_02,
        ], $maker);

        $this->assertSame(ReceivablePaymentRequest::STATUS_SUBMITTED, $request->status);
        $this->assertSame($maker->id, $request->requested_by);
        $this->assertNull($request->gl_to_gl_transaction_id);
        $this->assertNull($request->receivable_payment_id);
        $this->assertSame('10000.00', $receivable->refresh()->remaining_receivable_amount);
        $this->assertDatabaseCount('receivable_payments', 0);
        $this->assertDatabaseCount('gl_to_gl_transactions', 0);

        $approval = ApprovalRequest::query()->sole();
        $this->assertSame(ApprovalRequest::WORKFLOW_RECEIVABLE_PAYMENT, $approval->workflow_code);
        $this->assertSame(ApprovalRequest::STATUS_SUBMITTED, $approval->status);
        $this->assertSame('accounting_approver', ApprovalStep::query()->sole()->role_name);
        Http::assertNothingSent();
    }

    public function test_maker_debtor_saving_submit_reinquires_and_stores_snapshot(): void
    {
        Http::fake([
            'http://core.test/saving/inq/balance*' => Http::response($this->balanceResponse('7500.00')),
        ]);
        $maker = $this->userWithRole('accounting_maker', '000');
        $receivable = $this->receivable([
            'saving_account_for_loan_repayment' => '1000010000000691',
        ]);

        $request = app(SubmitReceivablePaymentRequestAction::class)->handle($receivable, [
            'amount' => '2500.00',
            'payment_source' => ReceivablePaymentRequest::PAYMENT_SOURCE_DEBTOR_SAVING,
        ], $maker);

        $this->assertSame('1000010000000691', $request->saving_account_number);
        $this->assertSame('Jane Customer', $request->saving_account_snapshot['customerName']);
        $this->assertSame('Saving Product', $request->saving_account_snapshot['productName']);
        $this->assertSame('Active', $request->saving_account_snapshot['documentStatus']);
        $this->assertSame('7500.00', $request->saving_account_snapshot['availableBalance']);
        $this->assertSame('8000.00', $request->saving_account_snapshot['ledgerBalance']);
        $this->assertArrayNotHasKey('minimumBalance', $request->saving_account_snapshot);
        $this->assertArrayNotHasKey('responseCode', $request->saving_account_snapshot);
        Http::assertSentCount(1);
    }

    public function test_current_account_approval_executes_rpg01_and_records_payment_only_after_gl_success(): void
    {
        Http::fake([
            'http://core.test/trx/transfer/gl-to-gl' => Http::response([
                'responseCode' => '00',
                'description' => 'Accepted',
                'data' => ['voucher' => 'V-1'],
            ]),
        ]);
        $maker = $this->userWithRole('accounting_maker', '000');
        $approver = $this->userWithRole('accounting_approver', '000');
        $receivable = $this->receivable();
        $claimStatusId = $receivable->claim_status_id;
        $request = $this->submitCurrentAccountRequest($receivable, $maker, '2500.00');

        $result = app(ExecuteReceivablePaymentRequestAction::class)->handle($request, $approver, 'approved');
        $payment = ReceivablePayment::query()->sole();
        $gl = GlToGlTransaction::query()->sole();

        $this->assertSame(ReceivablePaymentRequest::STATUS_PAYMENT_RECORDED, $result->status);
        $this->assertSame($payment->id, $result->receivable_payment_id);
        $this->assertSame($gl->id, $result->gl_to_gl_transaction_id);
        $this->assertSame($payment->id, $gl->receivable_payment_id);
        $this->assertSame('7500.00', $receivable->refresh()->remaining_receivable_amount);
        $this->assertSame($claimStatusId, $receivable->claim_status_id);
        $this->assertSame($approver->id, $payment->created_by);
        $this->assertSame('2026-07-07 10:20:30', $payment->paid_at?->toDateTimeString());
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $request->approvalRequest->refresh()->status);
        $this->assertSame(ApprovalStep::STATUS_APPROVED, ApprovalStep::query()->sole()->status);
        $this->assertSame('RPG01', $gl->request_payload['trxType']);
        $this->assertSame('', $gl->request_payload['debitAccount']);
        $this->assertSame('', $gl->request_payload['creditAccount']);
        $this->assertArrayNotHasKey('sourceAccount', $gl->request_payload);
        $this->assertStringNotContainsString('111101202', json_encode($gl->request_payload));
        $this->assertStringNotContainsString('1971000', json_encode($gl->request_payload));
        Http::assertSentCount(1);
    }

    public function test_debtor_saving_validation_failure_on_approval_keeps_approval_pending_and_skips_gl(): void
    {
        Http::fake([
            'http://core.test/saving/inq/balance*' => Http::sequence()
                ->push($this->balanceResponse('7500.00'))
                ->push($this->balanceResponse('7500.00', documentStatus: 'Dormant')),
            'http://core.test/trx/transfer/gl-to-gl' => Http::response([
                'responseCode' => '00',
                'description' => 'Should not be called',
            ]),
        ]);
        $maker = $this->userWithRole('accounting_maker', '000');
        $approver = $this->userWithRole('accounting_approver', '000');
        $receivable = $this->receivable(['saving_account_for_loan_repayment' => '1000010000000691']);
        $request = app(SubmitReceivablePaymentRequestAction::class)->handle($receivable, [
            'amount' => '2500.00',
            'payment_source' => ReceivablePaymentRequest::PAYMENT_SOURCE_DEBTOR_SAVING,
        ], $maker);

        $result = app(ExecuteReceivablePaymentRequestAction::class)->handle($request, $approver);

        $this->assertSame(ReceivablePaymentRequest::STATUS_VALIDATION_FAILED, $result->status);
        $this->assertStringContainsString('Active', $result->last_error_message);
        $this->assertSame(ApprovalRequest::STATUS_SUBMITTED, $request->approvalRequest->refresh()->status);
        $this->assertSame('10000.00', $receivable->refresh()->remaining_receivable_amount);
        $this->assertDatabaseCount('receivable_payments', 0);
        $this->assertDatabaseCount('gl_to_gl_transactions', 0);
        Http::assertSentCount(2);
    }

    public function test_gl_failure_keeps_pending_and_retry_uses_new_reference_before_success(): void
    {
        Http::fake([
            'http://core.test/trx/transfer/gl-to-gl' => Http::sequence()
                ->push(['responseCode' => '91', 'description' => 'Core timeout', 'data' => []])
                ->push(['responseCode' => '00', 'description' => 'Accepted', 'data' => []]),
        ]);
        $maker = $this->userWithRole('accounting_maker', '000');
        $approver = $this->userWithRole('accounting_approver', '000');
        $receivable = $this->receivable();
        $request = $this->submitCurrentAccountRequest($receivable, $maker, '2500.00');

        $failed = app(ExecuteReceivablePaymentRequestAction::class)->handle($request, $approver);
        $firstGl = GlToGlTransaction::query()->sole();

        $this->assertSame(ReceivablePaymentRequest::STATUS_GL_FAILED, $failed->status);
        $this->assertSame('Core timeout', $failed->last_error_message);
        $this->assertSame(GlToGlTransaction::STATUS_FAILED, $firstGl->status);
        $this->assertSame("RCPAY-{$request->id}-001", $firstGl->reference_number);
        $this->assertSame(ApprovalRequest::STATUS_SUBMITTED, $request->approvalRequest->refresh()->status);
        $this->assertDatabaseCount('receivable_payments', 0);
        $this->assertSame('10000.00', $receivable->refresh()->remaining_receivable_amount);

        $succeeded = app(ExecuteReceivablePaymentRequestAction::class)->handle($failed->refresh(), $approver);
        $retriedGl = GlToGlTransaction::query()->latest('id')->firstOrFail();

        $this->assertSame(ReceivablePaymentRequest::STATUS_PAYMENT_RECORDED, $succeeded->status);
        $this->assertNotSame($firstGl->id, $retriedGl->id);
        $this->assertSame("RCPAY-{$request->id}-002", $retriedGl->reference_number);
        $this->assertSame(GlToGlTransaction::STATUS_SUCCESS, $retriedGl->status);
        $this->assertSame(GlToGlTransaction::STATUS_FAILED, $firstGl->refresh()->status);
        $this->assertDatabaseCount('gl_to_gl_transactions', 2);
        $this->assertSame(
            ["RCPAY-{$request->id}-001", "RCPAY-{$request->id}-002"],
            CoreTransactionReference::query()->orderBy('id')->pluck('reference')->all(),
        );
        $this->assertDatabaseCount('receivable_payments', 1);
        $this->assertSame('7500.00', $receivable->refresh()->remaining_receivable_amount);

        app(ExecuteReceivablePaymentRequestAction::class)->handle($succeeded->refresh(), $approver);
        $this->assertDatabaseCount('receivable_payments', 1);
        Http::assertSentCount(2);
    }

    public function test_direct_payment_action_policy_and_relation_create_are_blocked(): void
    {
        $maker = $this->userWithRole('accounting_maker', '000');
        $receivable = $this->receivable();

        $this->assertFalse($maker->can('create', ReceivablePayment::class));
        $this->assertContains(ReceivablePaymentsRelationManager::class, InsuranceReceivableResource::getRelations());

        try {
            app(RecordReceivablePaymentAction::class)->handle($receivable, [
                'amount' => '100.00',
                'paid_at' => '2026-07-07',
            ], $maker);

            $this->fail('Direct payment action should be blocked.');
        } catch (ValidationException) {
        }

        Livewire::actingAs($maker)
            ->test(ReceivablePaymentsRelationManager::class, [
                'ownerRecord' => $receivable,
                'pageClass' => ViewInsuranceReceivable::class,
            ])
            ->assertTableHeaderActionsExistInOrder(['createPaymentRequest'])
            ->assertTableActionsExistInOrder([]);
    }

    public function test_recorded_payment_cannot_be_updated_or_deleted(): void
    {
        $user = $this->userWithRole('accounting_approver', '000');
        $receivable = $this->receivable();
        $payment = ReceivablePayment::query()->create([
            'insurance_receivable_id' => $receivable->id,
            'amount' => '100.00',
            'paid_at' => now(),
            'created_by' => $user->id,
        ]);

        try {
            $payment->forceFill(['amount' => '200.00'])->save();

            $this->fail('Recorded payment update should be rejected.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        try {
            $payment->delete();

            $this->fail('Recorded payment delete should be rejected.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    private function submitCurrentAccountRequest(InsuranceReceivable $receivable, User $maker, string $amount): ReceivablePaymentRequest
    {
        return app(SubmitReceivablePaymentRequestAction::class)->handle($receivable, [
            'amount' => $amount,
            'payment_source' => ReceivablePaymentRequest::PAYMENT_SOURCE_CURRENT_ACCOUNT_MANDIRI_02,
        ], $maker);
    }

    private function seedDependencies(): void
    {
        $this->seed([
            BranchOfficeSeeder::class,
            InsuranceCompanySeeder::class,
            ClaimStatusSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    private function userWithRole(string $role, string $branchCode): User
    {
        $branchOffice = BranchOffice::query()->where('branch_code', $branchCode)->firstOrFail();
        $user = User::factory()->create(['branch_office_id' => $branchOffice->id]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function receivable(array $attributes = []): InsuranceReceivable
    {
        return InsuranceReceivable::factory()->create([
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_CONFIRMATION_PENDING,
            'receivable_formation_date' => '2026-01-01',
            'receivable_amount' => '10000.00',
            'remaining_receivable_amount' => '10000.00',
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function balanceResponse(string $availableBalance, string $documentStatus = 'Active'): array
    {
        return [
            'responseCode' => '00',
            'description' => 'SUCCESS',
            'data' => [
                'accountNumber' => '1000010000000691',
                'customerName' => 'Jane Customer',
                'productName' => 'Saving Product',
                'documentStatus' => $documentStatus,
                'availableBalance' => $availableBalance,
                'ledgerBalance' => '8000.00',
            ],
        ];
    }
}
