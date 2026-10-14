<?php

namespace Tests\Feature;

use App\Actions\CkpnJournal\ExecuteGlToGlTransferAction;
use App\Actions\ClaimStatusChangeRequest\ApproveClaimStatusChangeRequestAction;
use App\Actions\ClaimStatusChangeRequest\CreateAndSubmitClaimStatusChangeFromReceivableAction;
use App\Actions\CoreBanking\ResolveCoreTransactionOutcomeAction;
use App\Actions\InsuranceReceivable\RejectInsuranceReceivableApprovalAction;
use App\Actions\InsuranceReceivable\StoreClaimDocumentAction;
use App\Actions\ReceivablePayment\ExecuteReceivablePaymentRequestAction;
use App\Actions\ReceivablePayment\SubmitReceivablePaymentRequestAction;
use App\Filament\Resources\ApprovalQueues\Pages\ListApprovalQueue;
use App\Filament\Resources\CkpnWorkpapers\CkpnWorkpaperResource;
use App\Filament\Resources\InsuranceReceivables\Pages\CreateInsuranceReceivable;
use App\Filament\Resources\InsuranceReceivables\Pages\EditInsuranceReceivable;
use App\Filament\Resources\InsuranceReceivables\Pages\ViewInsuranceReceivable;
use App\Filament\Resources\InsuranceReceivables\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Jobs\ExecuteGlToGlJob;
use App\Jobs\GenerateCkpnWorkpaperJob;
use App\Models\ApiIntegrationLog;
use App\Models\ApprovalRequest;
use App\Models\BranchOffice;
use App\Models\CkpnJournal;
use App\Models\CkpnWorkpaper;
use App\Models\ClaimStatus;
use App\Models\GeneratedExport;
use App\Models\GlToGlTransaction;
use App\Models\InsuranceCompany;
use App\Models\InsuranceReceivable;
use App\Models\InsuranceReceivableInstallmentRepayment;
use App\Models\ReceivablePaymentRequest;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\CoreBanking\CoreBankingClient;
use App\Services\CoreBanking\CoreBankingSignatureGenerator;
use App\Services\InsuranceReceivable\ResolveClaimDocumentChecklist;
use App\Services\KolekRpa\KolekRpaClient;
use App\Support\Export\SafeXlsxRow;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimDocumentSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use ReflectionMethod;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regressions for security audit run-1 (2026-10-14) findings.
 */
class SecurityAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $coreReferences = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['core_banking.base_url' => 'http://core.test', 'core_banking.signature_secret' => 'k']);
        Http::preventStrayRequests();
        Queue::fake();
        $this->seed([BranchOfficeSeeder::class, InsuranceCompanySeeder::class, ClaimStatusSeeder::class, ClaimDocumentSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_approval_queue_unknown_tab_keeps_branch_scope(): void
    {
        $victim = $this->userWithRole('branch_maker', '002');
        $receivable = InsuranceReceivable::factory()->create([
            'branch_office_id' => $victim->branch_office_id,
            'branch_code' => '002',
            'created_by' => $victim->id,
            'customer_name' => 'Other Branch Customer',
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_INQUIRY_COMPLETED,
        ]);
        app(ApprovalService::class)->submit($receivable, ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH, $victim);

        foreach (['branch_maker', 'branch_approver'] as $role) {
            $attacker = $this->userWithRole($role, '001');

            foreach (['zzz', 'all_pending'] as $tab) {
                Livewire::actingAs($attacker)->test(ListApprovalQueue::class)
                    ->set('activeTab', $tab)
                    ->assertDontSee('Other Branch Customer');
            }

            Livewire::actingAs($attacker)->withQueryParams(['tab' => 'q'])->test(ListApprovalQueue::class)
                ->assertDontSee('Other Branch Customer');
        }

        Livewire::actingAs($victim)->test(ListApprovalQueue::class)
            ->set('activeTab', 'my_requests')
            ->assertSee('Other Branch Customer');
    }

    public function test_claim_document_upload_rejects_existing_file_path(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('insurance-cover-letters/insurance-cover-letter-1.pdf', 'OTHER-BRANCH-SECRET');
        $attacker = $this->userWithRole('branch_maker', '001');
        $receivable = InsuranceReceivable::factory()->create([
            'branch_office_id' => $attacker->branch_office_id,
            'insurance_company_id' => InsuranceCompany::query()->where('name', 'TASPEN LIFE')->firstOrFail()->id,
            'created_by' => $attacker->id,
            'date_of_death' => '2026-01-15',
            'death_document_condition' => 'hospital',
        ]);
        $records = app(ResolveClaimDocumentChecklist::class)->handle($receivable)->tableRecords();
        $key = $records->firstWhere('code', 'insurance_certificate_copy')['__key'];

        foreach (['insurance-cover-letters/insurance-cover-letter-1.pdf', 'insurance-receivable-documents/../insurance-cover-letters/insurance-cover-letter-1.pdf'] as $path) {
            Livewire::actingAs($attacker)
                ->test(DocumentsRelationManager::class, ['ownerRecord' => $receivable, 'pageClass' => ViewInsuranceReceivable::class])
                ->callAction(TestAction::make('upload')->table($key), [
                    'file_path' => ['x' => $path],
                    'original_file_name' => ['x' => 'loot.pdf'],
                ]);
        }

        $this->assertSame(0, $receivable->documents()->count());

        $type = $records->firstWhere('code', 'insurance_certificate_copy')['document_type_id'];
        $this->expectException(ValidationException::class);
        app(StoreClaimDocumentAction::class)->handle($receivable, $type, 'insurance-cover-letters/insurance-cover-letter-1.pdf', 'x.pdf', $attacker);
    }

    public function test_stale_session_is_rejected_on_livewire_update_and_downloads(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['password' => Hash::make('OldSecret#2026')]);
        $admin->assignRole('super_admin');
        $target = User::factory()->create(['name' => 'Original Name']);
        Storage::disk('local')->put('exports/stale.xlsx', 'DUMMY');
        $export = GeneratedExport::query()->create([
            'exportable_type' => User::class, 'exportable_id' => $target->id,
            'export_type' => GeneratedExport::TYPE_CKPN_WORKPAPER_ITEMS_XLSX,
            'file_path' => 'exports/stale.xlsx', 'status' => GeneratedExport::STATUS_GENERATED,
            'generated_by' => $admin->id, 'generated_at' => now(),
        ]);

        // Resolve the user from the session cookie, not actingAs, like a stolen session would.
        $this->withSession(['login_web_'.sha1(SessionGuard::class) => $admin->id]);
        Auth::forgetGuards();
        $html = $this->get("/admin/users/{$target->id}/edit")->assertOk()->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
        $snapshot = collect($matches[1])
            ->map(fn (string $raw): string => html_entity_decode($raw, ENT_QUOTES))
            ->first(fn (string $raw): bool => str_ends_with(json_decode($raw, true)['memo']['name'] ?? '', 'EditUser'));
        $this->assertNotNull($snapshot);

        User::query()->whereKey($admin->id)->update(['password' => Hash::make('NewSecret#2026')]);

        Auth::forgetGuards();
        $this->withHeaders(['X-Livewire' => 'true'])->postJson('/'.ltrim(EndpointResolver::updatePath(), '/'), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => ['data.name' => 'Changed By Stale Session'],
                'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
            ]],
        ]);
        $this->assertSame('Original Name', $target->fresh()->name);

        Auth::forgetGuards();
        $this->get("/generated-exports/{$export->id}/download")->assertRedirect();
    }

    public function test_ckpn_gl_without_core_response_code_is_not_retried(): void
    {
        $this->fakeCore([Http::response('', 503), Http::response(['responseCode' => '00'])]);
        [$journal, $user] = $this->approvedJournal();

        $first = app(ExecuteGlToGlTransferAction::class)->handle($journal, $user);
        $this->assertSame(GlToGlTransaction::STATUS_UNKNOWN_TIMEOUT, $first->status);
        $this->assertSame(GlToGlTransaction::RESOLUTION_STATUS_RECONCILIATION_REQUIRED, $first->resolution_status);

        $this->assertValidationBlocked(fn () => app(ExecuteGlToGlTransferAction::class)->handle($journal->refresh(), $user));
        $this->assertCount(1, $this->coreReferences);
    }

    public function test_ckpn_gl_definite_rejection_is_retried_with_new_reference(): void
    {
        $this->fakeCore([Http::response(['responseCode' => '51', 'description' => 'Rejected']), Http::response(['responseCode' => '00'])]);
        [$journal, $user] = $this->approvedJournal();

        $this->assertSame(GlToGlTransaction::STATUS_FAILED, app(ExecuteGlToGlTransferAction::class)->handle($journal, $user)->status);
        $this->assertSame(GlToGlTransaction::STATUS_SUCCESS, app(ExecuteGlToGlTransferAction::class)->handle($journal->refresh(), $user)->status);
        $this->assertSame(["CKPNJ{$journal->id}001", "CKPNJ{$journal->id}002"], $this->coreReferences);
    }

    public function test_ckpn_gl_pending_attempt_after_worker_death_is_not_retried(): void
    {
        $this->fakeCore([Http::response(['responseCode' => '00'])]);
        [$journal, $user] = $this->approvedJournal();
        $this->app->bind(CoreBankingClient::class, fn ($app) => new class($app->make(CoreBankingSignatureGenerator::class)) extends CoreBankingClient
        {
            public function transferGlToGl(array $payload, ?Model $related = null, ?User $requestedBy = null): array
            {
                parent::transferGlToGl($payload, $related, $requestedBy);

                throw new RuntimeException('worker killed after send');
            }
        });
        $job = new ExecuteGlToGlJob($journal->id, $user->id);

        try {
            app()->call([$job, 'handle']);
        } catch (RuntimeException) {
        }
        $this->assertSame(GlToGlTransaction::STATUS_PENDING, GlToGlTransaction::query()->sole()->status);

        $this->assertValidationBlocked(fn () => app()->call([$job, 'handle']));
        $this->assertCount(1, $this->coreReferences);
    }

    public function test_receivable_payment_gl_without_core_response_code_is_not_retried(): void
    {
        $this->fakeCore([Http::response('', 504), Http::response(['responseCode' => '00'])]);
        $maker = $this->userWithRole('accounting_maker', '000');
        $approver = $this->userWithRole('accounting_approver', '000');
        $receivable = InsuranceReceivable::factory()->create([
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
            'system_status' => InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_PENDING,
            'receivable_formation_date' => '2026-01-01',
            'receivable_amount' => '9000.00',
            'remaining_receivable_amount' => '9000.00',
        ]);
        $request = app(SubmitReceivablePaymentRequestAction::class)->handle($receivable, [
            'amount' => '1234.00',
            'payment_source' => ReceivablePaymentRequest::PAYMENT_SOURCE_CURRENT_ACCOUNT_MANDIRI_02,
        ], $maker);

        $first = app(ExecuteReceivablePaymentRequestAction::class)->handle($request, $approver, 'first');
        $this->assertSame(ReceivablePaymentRequest::STATUS_RECONCILIATION_REQUIRED, $first->status);

        try {
            app(ExecuteReceivablePaymentRequestAction::class)->handle($first, $approver, 'retry');
        } catch (ValidationException) {
        }
        $this->assertCount(1, $this->coreReferences);
    }

    public function test_claim_status_cannot_be_set_from_receivable_form(): void
    {
        $maker = $this->userWithRole('branch_maker', '001');
        $default = (int) ClaimStatus::query()->where('code', ClaimStatus::DEFAULT_CODE)->value('id');
        $rejected = (int) ClaimStatus::query()->where('code', ClaimStatus::REJECTED_CODE)->value('id');

        Livewire::actingAs($maker)->test(CreateInsuranceReceivable::class)
            ->fillForm([
                'loan_account_number' => '1234567890',
                'date_of_death' => '2026-01-15',
                'death_document_condition' => 'hospital',
                'insurance_company_id' => InsuranceCompany::query()->value('id'),
            ])
            ->set('data.claim_status_id', $rejected)
            ->call('create')
            ->assertHasNoFormErrors();
        $created = InsuranceReceivable::query()->latest('id')->firstOrFail();
        $this->assertSame($default, (int) $created->claim_status_id);

        Livewire::actingAs($maker)->test(EditInsuranceReceivable::class, ['record' => $created->id])
            ->set('data.claim_status_id', $rejected)
            ->call('save');
        $this->assertSame($default, (int) $created->refresh()->claim_status_id);
    }

    public function test_loan_number_is_locked_after_oper_rpa_ran(): void
    {
        $maker = $this->userWithRole('branch_maker', '001');
        $receivable = InsuranceReceivable::factory()->create([
            'branch_office_id' => $maker->branch_office_id,
            'created_by' => $maker->id,
            'loan_account_number' => '0010010000777',
            'death_document_condition' => 'hospital',
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RETURNED_TO_BRANCH_MAKER,
        ]);

        Livewire::actingAs($maker)->test(EditInsuranceReceivable::class, ['record' => $receivable->id])
            ->fillForm(['loan_account_number' => '0010010000999'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('0010010000999', $receivable->refresh()->loan_account_number);

        $receivable->forceFill(['original_saving_account_for_loan_repayment' => '0017777777'])->saveQuietly();

        Livewire::actingAs($maker)->test(EditInsuranceReceivable::class, ['record' => $receivable->id])
            ->assertFormFieldIsDisabled('loan_account_number')
            ->set('data.loan_account_number', '0010010000888')
            ->call('save');
        $this->assertSame('0010010000999', $receivable->refresh()->loan_account_number);
    }

    public function test_workpaper_scope_has_no_edit_page(): void
    {
        // Period and branch drive generated items; there is no edit page to change them after creation.
        $this->assertArrayNotHasKey('edit', CkpnWorkpaperResource::getPages());
    }

    public function test_database_seeder_demo_users_are_local_only_and_keep_changed_passwords(): void
    {
        $this->app['env'] = 'production';
        app(DatabaseSeeder::class)->setContainer($this->app)->__invoke();
        $this->assertFalse(User::query()->where('email', 'test@example.com')->exists());

        $this->app['env'] = 'testing';
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('email', 'test@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('password', $admin->password));

        $admin->forceFill(['password' => 'Rotated#2026'])->save();
        $this->seed(DatabaseSeeder::class);
        $this->assertTrue(Hash::check('Rotated#2026', $admin->refresh()->password));
    }

    public function test_maker_checker_blocks_submitter_and_earlier_approver_but_not_super_admin(): void
    {
        $dual = $this->userWithRole('branch_maker', '001');
        $dual->assignRole('branch_approver');
        $own = $this->submittedClaim($dual);
        $this->assertFalse(app(ApprovalService::class)->canActOnCurrentStep($own, $dual));
        $this->assertValidationBlocked(fn () => app(ApprovalService::class)->approveCurrentStep($own, $dual));

        $maker = $this->userWithRole('branch_maker', '001');
        $approver = $this->userWithRole('branch_approver', '001');
        $approver->assignRole('insurance_approver');
        $request = app(ApprovalService::class)->approveCurrentStep($this->submittedClaim($maker), $approver);
        $this->assertFalse(app(ApprovalService::class)->canActOnCurrentStep($request, $approver));

        $admin = $this->userWithRole('super_admin', '000');
        $this->assertTrue(app(ApprovalService::class)->canActOnCurrentStep($this->submittedClaim($admin), $admin));
    }

    public function test_accounting_validation_cannot_be_rejected_after_repayment_reached_core(): void
    {
        $it = $this->userWithRole('it_user', '000');
        $approver = $this->userWithRole('accounting_approver', '000');
        $receivable = InsuranceReceivable::factory()->create([
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
            'date_of_death' => '2026-01-15',
        ]);
        app(ApprovalService::class)->submit($receivable, ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION, $it);
        InsuranceReceivableInstallmentRepayment::query()->create([
            'insurance_receivable_id' => $receivable->id,
            'account_number' => $receivable->loan_account_number,
            'branch_code' => '001',
            'installment_amount' => '100.00',
            'loan_outstanding_before' => '500.00',
            'date_of_death' => '2026-01-15',
            'status' => InsuranceReceivableInstallmentRepayment::STATUS_EXECUTED,
        ]);

        $this->assertValidationBlocked(fn () => app(RejectInsuranceReceivableApprovalAction::class)->handle($receivable, $approver));
        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION, $receivable->refresh()->workflow_status);
    }

    public function test_core_outcome_resolution_refuses_settled_transactions(): void
    {
        [$journal] = $this->approvedJournal();
        $transaction = $journal->glToGlTransactions()->create([
            'purpose' => GlToGlTransaction::PURPOSE_CKPN_JOURNAL,
            'attempt_no' => 1,
            'status' => GlToGlTransaction::STATUS_SUCCESS,
        ]);

        $this->assertValidationBlocked(fn () => app(ResolveCoreTransactionOutcomeAction::class)->handle(
            $transaction,
            GlToGlTransaction::RESOLUTION_OUTCOME_NOT_POSTED,
            $this->userWithRole('accounting_approver', '000'),
            'notes',
            ['ref' => 'x'],
        ));
        $this->assertSame(GlToGlTransaction::STATUS_SUCCESS, $transaction->refresh()->status);
    }

    public function test_claim_status_approval_refuses_when_status_changed_after_submission(): void
    {
        $maker = $this->userWithRole('business_maker', '000');
        $approver = $this->userWithRole('business_approver', '000');
        $receivable = InsuranceReceivable::factory()->create();
        $request = app(CreateAndSubmitClaimStatusChangeFromReceivableAction::class)->handle($receivable, $maker, [
            'to_claim_status_id' => ClaimStatus::query()->where('code', ClaimStatus::APPROVED_CODE)->value('id'),
        ]);
        $rejected = (int) ClaimStatus::query()->where('code', ClaimStatus::REJECTED_CODE)->value('id');
        $receivable->forceFill(['claim_status_id' => $rejected])->saveQuietly();

        $this->assertValidationBlocked(fn () => app(ApproveClaimStatusChangeRequestAction::class)->handle($request, $approver));
        $this->assertSame($rejected, (int) $receivable->refresh()->claim_status_id);
    }

    public function test_late_generation_failure_does_not_reset_generated_workpaper(): void
    {
        $workpaper = CkpnWorkpaper::query()->create([
            'period' => '2026-04-30', 'branch_office_id' => BranchOffice::query()->where('branch_code', '001')->value('id'),
            'status' => CkpnWorkpaper::STATUS_SUBMITTED,
            'total_receivable_amount' => '0.00', 'total_calculated_ckpn_amount' => '0.00',
            'total_adjustment_delta' => '0.00', 'total_effective_ckpn_amount' => '0.00', 'total_ckpn_amount' => '0.00',
        ]);

        (new GenerateCkpnWorkpaperJob($workpaper->id))->failed(new RuntimeException('late timeout'));

        $this->assertSame(CkpnWorkpaper::STATUS_SUBMITTED, $workpaper->refresh()->status);
    }

    public function test_api_integration_logs_are_central_only(): void
    {
        $branchUser = $this->userWithRole('branch_maker', '001');
        $branchUser->givePermissionTo(Permission::findOrCreate('ViewAny:ApiIntegrationLog'));

        $this->assertFalse($branchUser->can('viewAny', ApiIntegrationLog::class));
    }

    public function test_non_super_admin_cannot_grant_or_take_over_super_admin(): void
    {
        $manager = $this->userWithRole('it_user', '000');
        $manager->givePermissionTo(['ViewAny:User', 'View:User', 'Update:User', 'Create:User']);
        $admin = $this->userWithRole('super_admin', '000');
        $plain = $this->userWithRole('auditor', '000');

        $this->assertFalse($manager->can('update', $admin));
        $this->assertTrue($manager->can('update', $plain));

        $superAdminRoleId = Role::findByName('super_admin')->id;
        Livewire::actingAs($manager)->test(EditUser::class, ['record' => $plain->id])
            ->set('data.roles', [(string) $superAdminRoleId])
            ->call('save')
            ->assertHasFormErrors(['roles.0']);
        $this->assertFalse($plain->refresh()->hasRole('super_admin'));
    }

    public function test_xlsx_rows_never_emit_formula_cells(): void
    {
        $cells = SafeXlsxRow::fromValues(['=HYPERLINK("http://x")', 'plain', 12])->getCells();

        $this->assertInstanceOf(StringCell::class, $cells[0]);
        $this->assertNotInstanceOf(FormulaCell::class, $cells[0]);
        $this->assertInstanceOf(StringCell::class, $cells[1]);
    }

    public function test_fincloud_logs_redact_customer_identity(): void
    {
        $redact = new ReflectionMethod(KolekRpaClient::class, 'redactPii');
        $result = $redact->invoke(app(KolekRpaClient::class), ['data' => ['result' => [
            'id' => '0010010000777',
            'namanasabah' => 'Budi',
            'nocif' => 'CIF1',
            'dataalamat_ktp_alamat1' => 'Jl. Rahasia',
        ]]]);

        $this->assertSame(['id' => '0010010000777', 'namanasabah' => '[redacted]', 'nocif' => '[redacted]', 'dataalamat_ktp_alamat1' => '[redacted]'], $result['data']['result']);
    }

    private function submittedClaim(User $submitter): ApprovalRequest
    {
        $receivable = InsuranceReceivable::factory()->create([
            'branch_office_id' => $submitter->branch_office_id,
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
        ]);

        return app(ApprovalService::class)->submit($receivable, ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH, $submitter);
    }

    /**
     * @param  list<mixed>  $responses
     */
    private function fakeCore(array $responses): void
    {
        $call = 0;
        Http::fake(['http://core.test/*' => function (Request $request) use (&$call, $responses) {
            $this->coreReferences[] = $request->data()['referenceNumber'];

            return $responses[min($call++, count($responses) - 1)];
        }]);
    }

    /**
     * @return array{CkpnJournal, User}
     */
    private function approvedJournal(): array
    {
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $user = $this->userWithRole('accounting_approver', '001');
        $workpaper = CkpnWorkpaper::query()->create([
            'period' => '2026-05-15', 'branch_office_id' => $branch->id, 'status' => CkpnWorkpaper::STATUS_APPROVED,
            'total_receivable_amount' => '1000000.00', 'total_calculated_ckpn_amount' => '777.00',
            'total_adjustment_delta' => '0.00', 'total_effective_ckpn_amount' => '777.00', 'total_ckpn_amount' => '777.00',
        ]);
        $journal = CkpnJournal::query()->create([
            'ckpn_workpaper_id' => $workpaper->id, 'branch_office_id' => $branch->id, 'journal_date' => '2026-05-31',
            'total_amount' => '777.00', 'debit_account' => 'D-9', 'credit_account' => 'C-9',
            'debit_narrative' => 'd', 'credit_narrative' => 'c', 'description' => 'regression',
            'status' => CkpnJournal::STATUS_APPROVED,
        ]);

        return [$journal, $user];
    }

    private function userWithRole(string $role, string $branchCode): User
    {
        $user = User::factory()->create([
            'branch_office_id' => BranchOffice::query()->where('branch_code', $branchCode)->firstOrFail()->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function assertValidationBlocked(callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected the retry to be blocked.');
    }
}
