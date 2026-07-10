<?php

namespace Tests\Feature;

use App\Actions\CkpnJournal\CreateCkpnJournalFromWorkpaperAction;
use App\Actions\CkpnJournal\ExecuteGlToGlTransferAction;
use App\Actions\GeneratedExport\GenerateCkpnWorkpaperSakepExportAction;
use App\Filament\Resources\GlToGlTransactions\GlToGlTransactionResource;
use App\Models\ApiIntegrationLog;
use App\Models\BranchOffice;
use App\Models\CkpnJournal;
use App\Models\CkpnWorkpaper;
use App\Models\CkpnWorkpaperItem;
use App\Models\GeneratedExport;
use App\Models\GlToGlTransaction;
use App\Models\InsuranceReceivable;
use App\Models\User;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class CkpnJournalExportGlToGlTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_gl_to_gl_payload_signing_storage_and_retry_reuses_references(): void
    {
        config([
            'core_banking.base_url' => 'http://core.test',
            'core_banking.signature_secret' => 'secret-key',
        ]);
        Carbon::setTestNow('2026-05-31 10:20:30');
        $this->seedDependencies();
        $user = $this->userWithRole('accounting_approver', '000');
        $journal = $this->approvedJournal(totalAmount: '3077644.00');
        $expectedRawBody = '{"referenceNumber":"0531102030","trxType":"SAKEP CKPN","termType":"","termId":"FINCLOUD","receiptNumber":"0531102030","debitAccount":"D-1","creditAccount":"C-1","amount":"3077644.00","fee":"0","creditFee":"0","branchCode":"001","debitNarrative":"Debit narrative","creditNarrative":"Credit narrative","customerId":"","dateTime":"20260531102030","description":"CKPN journal","debitFee":"0","destAccount":"","currency":"IDR","srcAccType":"10","totalBill":"","type":"G2"}';
        $expectedLogRequestBody = json_decode($expectedRawBody, true, flags: JSON_THROW_ON_ERROR);

        Http::fake([
            'http://core.test/trx/transfer/gl-to-gl' => Http::sequence()
                ->push([
                    'result' => 'temporary_failure',
                    'payload' => ['kept' => true],
                ])
                ->push([
                    'responseCode' => '00',
                    'description' => 'Accepted',
                    'unknownResponse' => ['voucher' => 'V-1'],
                ]),
        ]);

        $first = app(ExecuteGlToGlTransferAction::class)->handle($journal, $user);

        $this->assertSame(GlToGlTransaction::STATUS_FAILED, $first->status);
        $this->assertSame('0531102030', $first->reference_number);
        $this->assertSame('0531102030', $first->receipt_number);
        $this->assertSame('3077644.00', $first->request_payload['amount']);
        $this->assertStringNotContainsString(',', $first->request_payload['amount']);
        $this->assertSame('3077644.00', $journal->refresh()->total_amount);

        $second = app(ExecuteGlToGlTransferAction::class)->handle($journal->refresh(), $user);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(GlToGlTransaction::STATUS_SUCCESS, $second->status);
        $this->assertSame('0531102030', $second->reference_number);
        $this->assertSame('0531102030', $second->receipt_number);
        $this->assertSame('00', $second->response_code);
        $this->assertSame('Accepted', $second->response_description);
        $this->assertSame('V-1', $second->response_payload['data']['unknownResponse']['voucher']);

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) use ($expectedRawBody): bool {
            return $request->url() === 'http://core.test/trx/transfer/gl-to-gl'
                && $request->body() === $expectedRawBody
                && $request->header('Signature')[0] === hash_hmac('sha256', $expectedRawBody, 'secret-key');
        });

        $this->assertSame(2, ApiIntegrationLog::query()->count());
        ApiIntegrationLog::query()->each(function (ApiIntegrationLog $log) use ($expectedLogRequestBody): void {
            $this->assertSame('/trx/transfer/gl-to-gl', $log->endpoint);
            $this->assertSame($expectedLogRequestBody, $log->request_body);
            $this->assertSame('[masked]', $log->request_headers['Signature']);
            $this->assertStringNotContainsString('secret-key', json_encode($log->request_headers));
        });

        $logs = ApiIntegrationLog::query()->orderBy('id')->get();
        $this->assertSame('temporary_failure', $logs[0]->response_body['result']);
        $this->assertSame('00', $logs[1]->response_body['responseCode']);
    }

    public function test_journal_must_be_approved_before_gl_to_gl_execute(): void
    {
        $this->seedDependencies();
        $user = $this->userWithRole('accounting_approver', '000');
        $journal = $this->approvedJournal();
        $journal->forceFill(['status' => CkpnJournal::STATUS_DRAFT])->save();

        $this->expectException(ValidationException::class);

        app(ExecuteGlToGlTransferAction::class)->handle($journal, $user);
    }

    public function test_approved_workpaper_generates_sakep_xlsx_with_required_columns(): void
    {
        Storage::fake(GeneratedExport::DISK);
        $this->seedDependencies();
        $user = $this->userWithRole('accounting_maker', '000');
        $workpaper = $this->approvedWorkpaperWithItem();

        $export = app(GenerateCkpnWorkpaperSakepExportAction::class)->handle($workpaper, $user);

        $this->assertSame(GeneratedExport::STATUS_GENERATED, $export->status);
        $this->assertSame(GeneratedExport::TYPE_CKPN_WORKPAPER_SAKEP_XLSX, $export->export_type);
        $this->assertStringContainsString('cutoff-2026-05-15', basename($export->file_path));
        $this->assertSame('2026-05-15', $export->metadata['cutoff_date']);
        $this->assertArrayNotHasKey('period', $export->metadata);
        Storage::disk(GeneratedExport::DISK)->assertExists($export->file_path);
        $this->assertDatabaseHas('generated_exports', [
            'id' => $export->id,
            'status' => GeneratedExport::STATUS_GENERATED,
        ]);

        $rows = $this->rowsFromXlsx(Storage::disk(GeneratedExport::DISK)->path($export->file_path));
        $headers = $rows[0];
        $dataRow = $rows[1];

        $this->assertSame([
            'no',
            'cif',
            'loan account number',
            'customer name',
            'credit limit',
            'date of realization/start period',
            'tenor/term',
            'maturity/end period',
            'date of death',
            'bade/loan outstanding',
            'receivable formation date',
            'insurance company',
            'claim status',
            'calculated CKPN amount',
            'adjusted CKPN amount',
            'effective CKPN rate',
            'effective CKPN amount',
            'final CKPN amount',
            'maker',
            'checker',
            'approver',
        ], $headers);
        $this->assertSame('100000.00', $dataRow[13]);
        $this->assertSame('120000.00', $dataRow[14]);
        $this->assertSame('12.0000', $dataRow[15]);
        $this->assertSame('120000.00', $dataRow[16]);
        $this->assertSame('120000.00', $dataRow[17]);
    }

    public function test_ckpn_journal_uses_effective_total_from_workpaper(): void
    {
        $this->seedDependencies();
        $user = $this->userWithRole('accounting_maker', '000');
        $workpaper = $this->approvedWorkpaperWithItem();

        $journal = app(CreateCkpnJournalFromWorkpaperAction::class)->handle($workpaper, $user, [
            'description' => 'Monthly CKPN',
        ]);

        $this->assertSame('120000.00', $journal->total_amount);
        $this->assertStringContainsString('Includes approved CKPN adjustments.', $journal->description);
    }

    public function test_ckpn_journal_creation_is_accounting_maker_only(): void
    {
        $this->seedDependencies();
        $businessMaker = $this->userWithRole('business_maker', '000');
        $accountingMaker = $this->userWithRole('accounting_maker', '000');
        $workpaper = $this->approvedWorkpaperWithItem();

        $this->assertFalse($businessMaker->can('createJournal', $workpaper));
        $this->assertTrue($accountingMaker->can('createJournal', $workpaper));

        try {
            app(CreateCkpnJournalFromWorkpaperAction::class)->handle($workpaper, $businessMaker);
            $this->fail('Business maker should not create CKPN journal.');
        } catch (ValidationException) {
        }

        $journal = app(CreateCkpnJournalFromWorkpaperAction::class)->handle($workpaper->refresh(), $accountingMaker);

        $this->assertSame($accountingMaker->id, $journal->created_by);
    }

    public function test_branch_user_cannot_view_other_branch_phase_six_records_and_auditor_cannot_execute(): void
    {
        $this->seedDependencies();
        $branchOneUser = $this->userWithRole('branch_maker', '001');
        $auditor = $this->userWithRole('auditor', '000');
        $ownWorkpaper = $this->approvedWorkpaper('001');
        $otherWorkpaper = $this->approvedWorkpaper('002');
        $ownJournal = $this->journalFor($ownWorkpaper);
        $otherJournal = $this->journalFor($otherWorkpaper);
        $ownExport = $ownWorkpaper->generatedExports()->create([
            'export_type' => GeneratedExport::TYPE_CKPN_WORKPAPER_SAKEP_XLSX,
            'file_path' => 'generated-exports/ckpn-workpapers/own.xlsx',
            'status' => GeneratedExport::STATUS_GENERATED,
        ]);
        $otherExport = $otherWorkpaper->generatedExports()->create([
            'export_type' => GeneratedExport::TYPE_CKPN_WORKPAPER_SAKEP_XLSX,
            'file_path' => 'generated-exports/ckpn-workpapers/other.xlsx',
            'status' => GeneratedExport::STATUS_GENERATED,
        ]);
        $ownGl = $ownJournal->glToGlTransactions()->create([
            'ckpn_workpaper_id' => $ownWorkpaper->id,
            'reference_number' => 'OWN',
            'receipt_number' => 'OWN-R',
            'status' => GlToGlTransaction::STATUS_PENDING,
        ]);
        $otherGl = $otherJournal->glToGlTransactions()->create([
            'ckpn_workpaper_id' => $otherWorkpaper->id,
            'reference_number' => 'OTHER',
            'receipt_number' => 'OTHER-R',
            'status' => GlToGlTransaction::STATUS_PENDING,
        ]);

        $this->assertTrue(Gate::forUser($branchOneUser)->allows('view', $ownJournal));
        $this->assertFalse(Gate::forUser($branchOneUser)->allows('view', $otherJournal));
        $this->assertTrue(Gate::forUser($branchOneUser)->allows('view', $ownExport));
        $this->assertFalse(Gate::forUser($branchOneUser)->allows('view', $otherExport));
        $this->assertFalse(Gate::forUser($branchOneUser)->allows('view', $ownGl));
        $this->assertFalse(Gate::forUser($branchOneUser)->allows('view', $otherGl));
        $this->assertTrue(Gate::forUser($auditor)->allows('view', $ownGl));

        $this->assertFalse(Gate::forUser($auditor)->allows('createJournal', $ownWorkpaper));
        $this->assertFalse(Gate::forUser($auditor)->allows('generateExport', $ownWorkpaper));
        $this->assertFalse(Gate::forUser($auditor)->allows('executeGlToGl', $ownJournal));
    }

    public function test_gl_to_gl_transaction_resource_is_only_for_super_admin_and_auditor(): void
    {
        $this->seedDependencies();
        $superAdmin = $this->userWithRole('super_admin', '000');
        $auditor = $this->userWithRole('auditor', '000');
        $accountingMaker = $this->userWithRole('accounting_maker', '000');
        $branchMaker = $this->userWithRole('branch_maker', '001');

        $this->actingAs($superAdmin);
        $this->assertTrue(GlToGlTransactionResource::shouldRegisterNavigation());
        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewAny', GlToGlTransaction::class));

        $this->actingAs($auditor);
        $this->assertTrue(GlToGlTransactionResource::shouldRegisterNavigation());
        $this->assertTrue(Gate::forUser($auditor)->allows('viewAny', GlToGlTransaction::class));

        $this->actingAs($accountingMaker);
        $this->assertFalse(GlToGlTransactionResource::shouldRegisterNavigation());
        $this->assertFalse(Gate::forUser($accountingMaker)->allows('viewAny', GlToGlTransaction::class));

        $this->actingAs($branchMaker);
        $this->assertFalse(GlToGlTransactionResource::shouldRegisterNavigation());
        $this->assertFalse(Gate::forUser($branchMaker)->allows('viewAny', GlToGlTransaction::class));
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

    private function approvedJournal(string $branchCode = '001', string $totalAmount = '1000.00'): CkpnJournal
    {
        return $this->journalFor($this->approvedWorkpaper($branchCode, $totalAmount));
    }

    private function approvedWorkpaper(string $branchCode = '001', string $totalAmount = '1000.00'): CkpnWorkpaper
    {
        $branch = BranchOffice::query()->where('branch_code', $branchCode)->firstOrFail();

        return CkpnWorkpaper::query()->create([
            'period' => '2026-05-15',
            'branch_office_id' => $branch->id,
            'status' => CkpnWorkpaper::STATUS_APPROVED,
            'total_receivable_amount' => '10000000.00',
            'total_calculated_ckpn_amount' => $totalAmount,
            'total_adjustment_delta' => '0.00',
            'total_effective_ckpn_amount' => $totalAmount,
            'total_ckpn_amount' => $totalAmount,
        ]);
    }

    private function approvedWorkpaperWithItem(): CkpnWorkpaper
    {
        $workpaper = $this->approvedWorkpaper('001', '120000.00');
        $workpaper->forceFill([
            'total_calculated_ckpn_amount' => '100000.00',
            'total_adjustment_delta' => '20000.00',
            'total_effective_ckpn_amount' => '120000.00',
            'total_ckpn_amount' => '120000.00',
        ])->save();
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $receivable = InsuranceReceivable::factory()->create([
            'branch_office_id' => $branch->id,
            'branch_code' => '001',
            'cif_no' => 'CIF-1',
            'loan_account_number' => '3010001000054745',
            'customer_name' => 'Customer One',
            'date_of_death' => '2026-01-15',
            'credit_limit' => '5000000.00',
            'loan_outstanding' => '1000000.00',
            'start_period' => '2025-01-01',
            'end_period' => '2027-01-01',
            'receivable_formation_date' => '2026-02-01',
            'receivable_amount' => '1000000.00',
        ]);

        CkpnWorkpaperItem::query()->create([
            'ckpn_workpaper_id' => $workpaper->id,
            'insurance_receivable_id' => $receivable->id,
            'origin_type' => InsuranceReceivable::ORIGIN_TYPE_WORKFLOW,
            'branch_code' => '001',
            'branch_name' => 'Cabang 001',
            'cif_no' => 'CIF-1',
            'loan_account_number' => '3010001000054745',
            'customer_name' => 'Customer One',
            'insurance_company_name' => 'VICTORIA ALIFE',
            'claim_status_code' => 'on_process',
            'claim_status_name' => 'ON PROSES',
            'claim_status_keterangan' => 'ON PROSES',
            'receivable_formation_date' => '2026-02-01',
            'receivable_amount' => '1000000.00',
            'remaining_receivable_amount' => '1000000.00',
            'age_days' => 119,
            'age_bucket_name' => '1 - 6 bulan',
            'insurance_company_weight' => '0.0000',
            'age_weight' => '0.0000',
            'claim_status_weight' => '0.0000',
            'calculated_ckpn_rate' => '10.0000',
            'calculated_ckpn_amount' => '100000.00',
            'adjusted_ckpn_rate' => '12.0000',
            'adjusted_ckpn_amount' => '120000.00',
            'adjustment_applied_at' => now(),
            'effective_ckpn_rate' => '12.0000',
            'effective_ckpn_amount' => '120000.00',
            'calculation_rule_code' => 'average_three_factors',
            'calculation_explanation' => 'Snapshot',
            'snapshot' => [
                'source' => 'Insurance Receivable',
                'date_of_death' => '2026-01-15',
                'credit_limit' => '5000000.00',
                'loan_outstanding' => '1000000.00',
                'start_period' => '2025-01-01',
                'end_period' => '2027-01-01',
            ],
        ]);

        return $workpaper->refresh();
    }

    private function journalFor(CkpnWorkpaper $workpaper): CkpnJournal
    {
        return CkpnJournal::query()->create([
            'ckpn_workpaper_id' => $workpaper->id,
            'branch_office_id' => $workpaper->branch_office_id,
            'journal_date' => '2026-05-31',
            'total_amount' => $workpaper->total_effective_ckpn_amount,
            'debit_account' => 'D-1',
            'credit_account' => 'C-1',
            'debit_narrative' => 'Debit narrative',
            'credit_narrative' => 'Credit narrative',
            'description' => 'CKPN journal',
            'status' => CkpnJournal::STATUS_APPROVED,
        ]);
    }

    /**
     * @return list<list<bool|\DateInterval|\DateTimeInterface|float|int|string|null>>
     */
    private function rowsFromXlsx(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(
                    fn ($cell): bool|\DateInterval|\DateTimeInterface|float|int|string|null => $cell->getValue(),
                    $row->getCells(),
                );
            }
        }

        $reader->close();

        return $rows;
    }

    private function userWithRole(string $role, string $branchCode): User
    {
        $branchOffice = BranchOffice::query()->where('branch_code', $branchCode)->firstOrFail();
        $user = User::factory()->create(['branch_office_id' => $branchOffice->id]);
        $user->assignRole($role);

        return $user;
    }
}
