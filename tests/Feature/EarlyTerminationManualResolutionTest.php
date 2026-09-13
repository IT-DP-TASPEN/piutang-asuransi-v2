<?php

namespace Tests\Feature;

use App\Actions\InsuranceReceivable\PerformLoanInquiryAction;
use App\Actions\InsuranceReceivable\ResolveEarlyTerminationManuallyAction;
use App\Actions\InsuranceReceivable\SubmitManualEarlyTerminationConfirmationAction;
use App\Filament\Resources\InsuranceReceivables\Pages\ViewInsuranceReceivable;
use App\Models\ApiIntegrationLog;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\BranchOffice;
use App\Models\InsuranceReceivable;
use App\Models\User;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class EarlyTerminationManualResolutionTest extends TestCase
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

    public function test_accounting_maker_confirmation_is_idempotent_and_creates_single_approval_request(): void
    {
        Http::fake();
        $receivable = $this->receivable();
        $maker = $this->userWithRole('accounting_maker', '000');
        $approver = $this->userWithRole('accounting_approver', '000');

        $submitted = app(SubmitManualEarlyTerminationConfirmationAction::class)
            ->handle($receivable, $maker, 'Executed manually in core.');
        $again = app(SubmitManualEarlyTerminationConfirmationAction::class)
            ->handle($submitted, $maker, 'Duplicate click.');

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_SUBMITTED, $again->workflow_status);
        $this->assertSame(InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED, $again->system_status);
        $request = ApprovalRequest::query()->sole();
        $this->assertSame(ApprovalRequest::WORKFLOW_MANUAL_EARLY_TERMINATION_VERIFICATION, $request->workflow_code);
        $this->assertSame(ApprovalRequest::STATUS_SUBMITTED, $request->status);
        $this->assertSame($maker->id, $request->submitted_by);
        $this->assertSame('accounting_approver', ApprovalStep::query()->sole()->role_name);
        $this->assertTrue($maker->can('submitManualEarlyTerminationConfirmation', $again));
        $this->assertFalse($maker->can('resolveEarlyTermination', $again));
        $this->assertFalse($approver->can('submitManualEarlyTerminationConfirmation', $receivable));
        $this->assertTrue($approver->can('resolveEarlyTermination', $again));
        $this->assertSame(1, $again->stageLogs()
            ->where('event', 'manual_early_termination_confirmation_submitted')
            ->count());
        Http::assertNothingSent();
    }

    public function test_code_77_resolves_manual_execution_required_and_writes_complete_audit(): void
    {
        $receivable = $this->manualSubmittedReceivable('Maker submitted ET confirmation.');
        $user = $this->userWithRole('accounting_approver', '000');
        $expectedBody = '{"accountNumber":"3010010000000068"}';
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response([
                'responseCode' => '77',
                'description' => 'Data Not Found',
                'data' => [],
            ]),
        ]);

        $resolved = app(ResolveEarlyTerminationManuallyAction::class)
            ->handle($receivable, $user, 'Verified manual ET.');

        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_RESOLVED, $resolved->workflow_status);
        $this->assertSame(InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_RESOLVED, $resolved->system_status);
        $this->assertNull($resolved->last_error_message);
        $this->assertNotNull($resolved->early_termination_resolved_at);

        $apiLog = ApiIntegrationLog::query()->sole();
        $this->assertSame('/inquiry/detail/loan', $apiLog->endpoint);
        $this->assertSame(['accountNumber' => '3010010000000068'], $apiLog->request_body);
        $this->assertSame('77', $apiLog->response_code);
        $this->assertFalse($apiLog->is_success);
        $this->assertSame($receivable->id, $apiLog->related_id);

        $stageLog = $resolved->stageLogs()
            ->where('event', 'early_termination_resolved_after_manual_core_execution')
            ->sole();
        $this->assertSame($apiLog->id, $stageLog->api_integration_log_id);
        $this->assertSame('77', $stageLog->metadata['verification_response_code']);
        $this->assertSame('Data Not Found', $stageLog->metadata['verification_response_description']);
        $this->assertSame($apiLog->id, $stageLog->metadata['api_integration_log_id']);
        $this->assertSame('3010010000000068', $stageLog->metadata['loan_account_number']);
        $this->assertSame('Maker submitted ET confirmation.', $stageLog->metadata['maker_submitted_notes']);
        $this->assertSame('Verified manual ET.', $stageLog->metadata['approver_notes']);
        $request = ApprovalRequest::query()->sole();
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $request->status);
        $this->assertNotNull($request->final_approved_at);
        $this->assertSame(ApprovalStep::STATUS_APPROVED, ApprovalStep::query()->sole()->status);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://core.test/inquiry/detail/loan'
            && $request->body() === $expectedBody
            && $request->header('Signature')[0] === hash_hmac('sha256', $expectedBody, 'secret-key'));
    }

    public function test_code_77_is_also_required_and_accepted_for_failed_early_termination(): void
    {
        $receivable = $this->receivable([
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_FAILED,
        ]);
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response([
                'responseCode' => '77',
                'description' => 'Data Not Found',
                'data' => [],
            ]),
        ]);

        $resolved = app(ResolveEarlyTerminationManuallyAction::class)
            ->handle($receivable, $this->userWithRole('accounting_approver', '000'));

        $this->assertSame(InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_RESOLVED, $resolved->system_status);
    }

    public function test_code_00_does_not_resolve_and_api_log_persists(): void
    {
        $receivable = $this->manualSubmittedReceivable();
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response([
                'responseCode' => '00',
                'description' => 'Success',
                'data' => ['accountNumber' => '3010010000000068'],
            ]),
        ]);

        try {
            app(ResolveEarlyTerminationManuallyAction::class)
                ->handle($receivable, $this->userWithRole('accounting_approver', '000'));
            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('still found', $exception->errors()['loan_account_number'][0]);
        }

        $this->assertSame(
            InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED,
            $receivable->refresh()->system_status,
        );
        $this->assertSame(ApprovalRequest::STATUS_SUBMITTED, ApprovalRequest::query()->sole()->status);
        $this->assertDatabaseHas('api_integration_logs', [
            'related_id' => $receivable->id,
            'response_code' => '00',
            'is_success' => true,
        ]);
    }

    public function test_other_response_and_transport_error_do_not_resolve_and_logs_persist(): void
    {
        $receivable = $this->manualSubmittedReceivable();
        $user = $this->userWithRole('accounting_approver', '000');
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::sequence()
                ->push(['responseCode' => '91', 'description' => 'Core unavailable', 'data' => []])
                ->pushFailedConnection('Connection timed out.'),
        ]);

        foreach (['Core unavailable', 'Connection timed out.'] as $expectedMessage) {
            try {
                app(ResolveEarlyTerminationManuallyAction::class)->handle($receivable->refresh(), $user);
                $this->fail('ValidationException was not thrown.');
            } catch (ValidationException $exception) {
                $this->assertSame($expectedMessage, $exception->errors()['loan_account_number'][0]);
            }
        }

        $this->assertSame(
            InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED,
            $receivable->refresh()->system_status,
        );
        $this->assertSame(ApprovalRequest::STATUS_SUBMITTED, ApprovalRequest::query()->sole()->status);
        $this->assertSame(2, ApiIntegrationLog::query()->count());
        $this->assertSame('Connection timed out.', ApiIntegrationLog::query()->latest('id')->firstOrFail()->error_message);
    }

    public function test_overlap_lock_blocks_duplicate_verification_without_http_call(): void
    {
        $receivable = $this->manualSubmittedReceivable();
        $lock = Cache::lock("insurance-receivable:{$receivable->id}:resolve-early-termination", 120);
        $this->assertTrue($lock->get());
        Http::fake();

        try {
            try {
                app(ResolveEarlyTerminationManuallyAction::class)
                    ->handle($receivable, $this->userWithRole('accounting_approver', '000'));
                $this->fail('ValidationException was not thrown.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('already in progress', $exception->errors()['system_status'][0]);
            }
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
    }

    public function test_final_revalidation_prevents_resolution_when_status_changes_during_inquiry(): void
    {
        $receivable = $this->manualSubmittedReceivable();
        Http::fake(function () use ($receivable) {
            $receivable->forceFill([
                'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_PENDING,
            ])->saveQuietly();

            return Http::response([
                'responseCode' => '77',
                'description' => 'Data Not Found',
                'data' => [],
            ]);
        });

        try {
            app(ResolveEarlyTerminationManuallyAction::class)
                ->handle($receivable, $this->userWithRole('accounting_approver', '000'));
            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('system_status', $exception->errors());
        }

        $this->assertSame(
            InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_PENDING,
            $receivable->refresh()->system_status,
        );
        $this->assertDatabaseCount('api_integration_logs', 1);
        $this->assertFalse($receivable->stageLogs()
            ->where('event', 'early_termination_resolved_after_manual_core_execution')
            ->exists());
    }

    public function test_normal_inquiry_still_rejects_response_code_77(): void
    {
        $receivable = $this->receivable([
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
        ]);
        Http::fake([
            'http://core.test/inquiry/detail/loan' => Http::response([
                'responseCode' => '77',
                'description' => 'Data Not Found',
                'data' => [],
            ]),
        ]);

        $this->expectException(ValidationException::class);

        app(PerformLoanInquiryAction::class)
            ->handle($receivable, $this->userWithRole('branch_maker', '001'));
    }

    public function test_filament_distinguishes_retry_from_resolve_and_enforces_authorization(): void
    {
        $manualPending = $this->receivable();
        $manualSubmitted = $this->manualSubmittedReceivable();
        $failed = $this->receivable([
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_FAILED,
            'saving_account_for_loan_repayment' => '001000OPER',
        ]);
        $topUpFailed = $this->receivable([
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_TOP_UP_FAILED,
            'saving_account_for_loan_repayment' => '001000OPER',
        ]);
        $accounting = $this->userWithRole('accounting_approver', '000');
        $maker = $this->userWithRole('accounting_maker', '000');

        Livewire::actingAs($maker)
            ->test(ViewInsuranceReceivable::class, ['record' => $manualPending->id])
            ->assertActionVisible('submitManualEarlyTerminationConfirmation')
            ->assertActionHidden('resolveEarlyTermination');

        Livewire::actingAs($accounting)
            ->test(ViewInsuranceReceivable::class, ['record' => $manualSubmitted->id])
            ->assertActionVisible('resolveEarlyTermination')
            ->assertActionHidden('retryEarlyTermination')
            ->assertActionHidden('submitManualEarlyTerminationConfirmation')
            ->assertActionDoesNotExist('confirmManualTopUpAndExecuteEarlyTermination')
            ->assertActionHasLabel('resolveEarlyTermination', 'Verify & Resolve Early Termination');

        Livewire::actingAs($accounting)
            ->test(ViewInsuranceReceivable::class, ['record' => $failed->id])
            ->assertActionVisible('resolveEarlyTermination')
            ->assertActionVisible('retryEarlyTermination')
            ->assertActionHasLabel('retryEarlyTermination', 'Retry Early Termination');

        Livewire::actingAs($accounting)
            ->test(ViewInsuranceReceivable::class, ['record' => $topUpFailed->id])
            ->assertActionVisible('retryEarlyTermination');

        Livewire::actingAs($this->userWithRole('branch_maker', '001'))
            ->test(ViewInsuranceReceivable::class, ['record' => $manualSubmitted->id])
            ->assertActionHidden('resolveEarlyTermination');
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
            'saving_account_for_loan_repayment' => 'MANUAL-ACCOUNT-01',
            'loan_outstanding' => '1000.00',
            'receivable_amount' => '1000.00',
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_MANUAL_EARLY_TERMINATION_PENDING,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_MANUAL_EXECUTION_REQUIRED,
            'last_error_message' => 'Manual Early Termination execution recorded.',
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function manualSubmittedReceivable(?string $notes = 'Manual ET completed.', array $attributes = []): InsuranceReceivable
    {
        $receivable = $this->receivable($attributes);

        return app(SubmitManualEarlyTerminationConfirmationAction::class)
            ->handle($receivable, $this->userWithRole('accounting_maker', '000'), $notes);
    }

    private function userWithRole(string $role, string $branchCode): User
    {
        $branch = BranchOffice::query()->where('branch_code', $branchCode)->firstOrFail();
        $user = User::factory()->create(['branch_office_id' => $branch->id]);
        $user->assignRole($role);

        return $user;
    }
}
