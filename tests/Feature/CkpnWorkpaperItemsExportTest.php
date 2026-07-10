<?php

namespace Tests\Feature;

use App\Actions\GeneratedExport\GenerateCkpnWorkpaperItemsExportAction;
use App\Actions\GeneratedExport\GenerateCkpnWorkpaperSakepExportAction;
use App\Filament\Resources\CkpnWorkpapers\Pages\ViewCkpnWorkpaper;
use App\Filament\Resources\CkpnWorkpapers\RelationManagers\ItemsRelationManager;
use App\Models\BranchOffice;
use App\Models\CkpnWorkpaper;
use App\Models\CkpnWorkpaperItem;
use App\Models\GeneratedExport;
use App\Models\InsuranceReceivable;
use App\Models\User;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class CkpnWorkpaperItemsExportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_items_export_generates_xlsx_from_workpaper_item_snapshots(): void
    {
        Storage::fake(GeneratedExport::DISK);
        Carbon::setTestNow('2026-06-24 10:20:30');
        $this->seedDependencies();
        $user = $this->userWithRole('accounting_maker', '000');
        $workpaper = $this->workpaper(CkpnWorkpaper::STATUS_SUBMITTED, '001');
        $receivable = InsuranceReceivable::factory()->create([
            'branch_office_id' => $workpaper->branch_office_id,
            'branch_code' => '001',
            'loan_account_number' => 'LIVE-LOAN',
            'alt_number' => 'LIVE-ALT',
            'date_of_death' => '2026-05-01',
            'receivable_amount' => '999999.99',
            'remaining_receivable_amount' => '999999.99',
        ]);
        $this->item($workpaper, [
            'insurance_receivable_id' => $receivable->id,
            'origin_type' => InsuranceReceivable::ORIGIN_TYPE_WORKFLOW,
            'loan_account_number' => 'SNAP-LOAN',
            'receivable_amount' => '1000.50',
            'remaining_receivable_amount' => '777.77',
            'claim_status_keterangan' => 'SNAPSHOT KETERANGAN',
            'calculated_ckpn_amount' => '100.10',
            'effective_ckpn_amount' => '150.30',
            'snapshot' => [
                'source' => 'Insurance Receivable',
                'date_of_death' => '2026-01-15',
                'loan_alt_account_number' => 'SNAP-ALT',
                'adjustment_delta' => '222.22',
            ],
        ]);
        $this->item($workpaper, [
            'origin_type' => InsuranceReceivable::ORIGIN_TYPE_LEGACY,
            'loan_account_number' => 'SNAP-LOAN-2',
            'calculated_ckpn_amount' => '100.10',
            'effective_ckpn_amount' => '100.30',
            'adjustment_reason' => null,
            'snapshot' => [
                'source' => 'Legacy',
                'date_of_death' => '2026-02-15',
            ],
        ]);
        $otherWorkpaper = $this->workpaper(CkpnWorkpaper::STATUS_SUBMITTED, '002');
        $this->item($otherWorkpaper, ['customer_name' => 'Other Workpaper Customer']);

        $receivable->forceFill([
            'loan_account_number' => 'LIVE-CHANGED',
            'alt_number' => 'LIVE-CHANGED-ALT',
            'remaining_receivable_amount' => '888888.88',
        ])->save();

        $export = app(GenerateCkpnWorkpaperItemsExportAction::class)->handle($workpaper, $user);

        $this->assertSame(GeneratedExport::STATUS_GENERATED, $export->status);
        $this->assertSame(GeneratedExport::TYPE_CKPN_WORKPAPER_ITEMS_XLSX, $export->export_type);
        $this->assertStringStartsWith('generated-exports/ckpn-workpapers/items/ckpn-workpaper-items-', $export->file_path);
        $this->assertStringContainsString('cutoff-2026-06-15', basename($export->file_path));
        $this->assertSame(GeneratedExport::DISK, $export->metadata['disk']);
        $this->assertSame(2, $export->metadata['items_count']);
        $this->assertSame('2026-06-15', $export->metadata['cutoff_date']);
        $this->assertArrayNotHasKey('period', $export->metadata);
        Storage::disk(GeneratedExport::DISK)->assertExists($export->file_path);

        $rows = $this->rowsFromXlsx(Storage::disk(GeneratedExport::DISK)->path($export->file_path));

        $this->assertCount(3, $rows);
        $this->assertSame($this->headers(), $rows[0]);
        $this->assertSame('SNAP-LOAN', $rows[1][5]);
        $this->assertSame('SNAP-ALT', $rows[1][6]);
        $this->assertSame('Customer One', $rows[1][4]);
        $this->assertSame('SNAPSHOT KETERANGAN', $rows[1][11]);
        $this->assertSame(1000.50, $rows[1][13]);
        $this->assertSame(777.77, $rows[1][14]);
        $this->assertEqualsWithDelta(0.25, $rows[1][15], 0.0000001);
        $this->assertEqualsWithDelta(0.05, $rows[1][16], 0.0000001);
        $this->assertEqualsWithDelta(0.10, $rows[1][17], 0.0000001);
        $this->assertEqualsWithDelta(0.125, $rows[1][18], 0.0000001);
        $this->assertSame(100.10, $rows[1][19]);
        $this->assertSame(222.22, $rows[1][20]);
        $this->assertSame(150.30, $rows[1][21]);
        $this->assertSame('Approved adjustment note', $rows[1][22]);
        $this->assertInstanceOf(DateTimeInterface::class, $rows[1][8]);
        $this->assertSame('2026-01-15', $rows[1][8]->format('Y-m-d'));
        $this->assertSame('2026-02-01', $rows[1][9]->format('Y-m-d'));

        $this->assertSame('SNAP-LOAN-2', $rows[2][5]);
        $this->assertEqualsWithDelta(0.20, $rows[2][20], 0.0000001);
        $this->assertNotContains('Other Workpaper Customer', array_column($rows, 4));

        $this->actingAs($user)
            ->get(route('generated-exports.download', $export))
            ->assertOk()
            ->assertDownload(basename($export->file_path));

        $otherBranchUser = $this->userWithRole('branch_maker', '002');
        $this->actingAs($otherBranchUser)
            ->get(route('generated-exports.download', $export))
            ->assertForbidden();
    }

    public function test_items_export_rejects_workpaper_without_items(): void
    {
        $this->seedDependencies();
        $user = $this->userWithRole('accounting_maker', '000');
        $workpaper = $this->workpaper(CkpnWorkpaper::STATUS_GENERATION_PROCESSING, '001');

        try {
            app(GenerateCkpnWorkpaperItemsExportAction::class)->handle($workpaper, $user);
            $this->fail('Empty CKPN workpaper item export should fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'CKPN workpaper has no generated items to export.',
                collect($exception->errors())->flatten()->first(),
            );
        }

        $this->assertSame(0, GeneratedExport::query()->count());
    }

    public function test_items_relation_manager_export_action_visibility_and_execution(): void
    {
        Storage::fake(GeneratedExport::DISK);
        $this->seedDependencies();
        $accountingMaker = $this->userWithRole('accounting_maker', '000');
        $businessMaker = $this->userWithRole('business_maker', '000');
        $workpaper = $this->workpaper(CkpnWorkpaper::STATUS_RETURNED, '001');
        $this->item($workpaper);
        $emptyWorkpaper = $this->workpaper(CkpnWorkpaper::STATUS_GENERATION_QUEUED, '002');

        Livewire::actingAs($accountingMaker)
            ->test(ItemsRelationManager::class, [
                'ownerRecord' => $workpaper,
                'pageClass' => ViewCkpnWorkpaper::class,
            ])
            ->assertTableHeaderActionsExistInOrder(['exportCkpnItems'])
            ->assertActionVisible('exportCkpnItems')
            ->assertActionEnabled('exportCkpnItems')
            ->callAction('exportCkpnItems')
            ->assertNotified('CKPN items XLSX generated');

        $this->assertSame(1, GeneratedExport::query()
            ->where('export_type', GeneratedExport::TYPE_CKPN_WORKPAPER_ITEMS_XLSX)
            ->where('status', GeneratedExport::STATUS_GENERATED)
            ->count());

        Livewire::actingAs($accountingMaker)
            ->test(ItemsRelationManager::class, [
                'ownerRecord' => $emptyWorkpaper,
                'pageClass' => ViewCkpnWorkpaper::class,
            ])
            ->assertActionVisible('exportCkpnItems')
            ->assertActionDisabled('exportCkpnItems');

        Livewire::actingAs($businessMaker)
            ->test(ItemsRelationManager::class, [
                'ownerRecord' => $workpaper,
                'pageClass' => ViewCkpnWorkpaper::class,
            ])
            ->assertActionHidden('exportCkpnItems');
    }

    public function test_sakep_export_remains_approved_only_and_latest_sakep_lookup_ignores_item_exports(): void
    {
        Storage::fake(GeneratedExport::DISK);
        $this->seedDependencies();
        $user = $this->userWithRole('accounting_maker', '000');
        $generatedWorkpaper = $this->workpaper(CkpnWorkpaper::STATUS_GENERATED, '001');
        $this->item($generatedWorkpaper);

        try {
            app(GenerateCkpnWorkpaperSakepExportAction::class)->handle($generatedWorkpaper, $user);
            $this->fail('SAKEP export should remain approved-only.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'SAKEP export can only be generated from an approved CKPN workpaper.',
                collect($exception->errors())->flatten()->first(),
            );
        }

        $approvedWorkpaper = $this->workpaper(CkpnWorkpaper::STATUS_APPROVED, '002');
        $this->item($approvedWorkpaper);
        $sakep = $approvedWorkpaper->generatedExports()->create([
            'export_type' => GeneratedExport::TYPE_CKPN_WORKPAPER_SAKEP_XLSX,
            'file_path' => 'generated-exports/ckpn-workpapers/sakep.xlsx',
            'status' => GeneratedExport::STATUS_GENERATED,
        ]);
        $approvedWorkpaper->generatedExports()->create([
            'export_type' => GeneratedExport::TYPE_CKPN_WORKPAPER_ITEMS_XLSX,
            'file_path' => 'generated-exports/ckpn-workpapers/items/items.xlsx',
            'status' => GeneratedExport::STATUS_GENERATED,
        ]);

        Livewire::actingAs($user)
            ->test(ViewCkpnWorkpaper::class, ['record' => $approvedWorkpaper->id])
            ->assertActionVisible('downloadLatestSakepExport')
            ->assertActionHasUrl('downloadLatestSakepExport', route('generated-exports.download', $sakep));
    }

    private function seedDependencies(): void
    {
        $this->seed([
            BranchOfficeSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    private function workpaper(string $status, string $branchCode): CkpnWorkpaper
    {
        $branch = BranchOffice::query()->where('branch_code', $branchCode)->firstOrFail();

        return CkpnWorkpaper::query()->create([
            'period' => '2026-06-15',
            'branch_office_id' => $branch->id,
            'status' => $status,
            'total_receivable_amount' => '1000.50',
            'total_calculated_ckpn_amount' => '100.10',
            'total_adjustment_delta' => '50.20',
            'total_effective_ckpn_amount' => '150.30',
            'total_ckpn_amount' => '150.30',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function item(CkpnWorkpaper $workpaper, array $overrides = []): CkpnWorkpaperItem
    {
        $originType = $overrides['origin_type'] ?? InsuranceReceivable::ORIGIN_TYPE_WORKFLOW;
        $receivableId = $overrides['insurance_receivable_id'] ?? (
            $originType === InsuranceReceivable::ORIGIN_TYPE_LEGACY
                ? InsuranceReceivable::factory()->legacy()->create()->id
                : InsuranceReceivable::factory()->create()->id
        );

        return CkpnWorkpaperItem::query()->create([
            'ckpn_workpaper_id' => $workpaper->id,
            'insurance_receivable_id' => $receivableId,
            'origin_type' => $originType,
            'branch_code' => '001',
            'branch_name' => 'Cabang 001',
            'cif_no' => 'CIF-1',
            'loan_account_number' => 'SNAP-LOAN',
            'customer_name' => 'Customer One',
            'insurance_company_name' => 'ASKRINDO',
            'claim_status_code' => 'on_process',
            'claim_status_name' => 'ON PROSES',
            'claim_status_keterangan' => 'ON PROSES',
            'receivable_formation_date' => '2026-02-01',
            'receivable_amount' => '1000.50',
            'remaining_receivable_amount' => '1000.50',
            'age_days' => 143,
            'age_bucket_name' => '1 - 6 bulan',
            'insurance_company_weight' => '25.0000',
            'age_weight' => '5.0000',
            'claim_status_weight' => '10.0000',
            'calculated_ckpn_rate' => '12.5000',
            'calculated_ckpn_amount' => '100.10',
            'adjusted_ckpn_rate' => '15.0000',
            'adjusted_ckpn_amount' => '150.30',
            'adjustment_applied_at' => now(),
            'adjustment_reason' => 'Approved adjustment note',
            'effective_ckpn_rate' => '15.0000',
            'effective_ckpn_amount' => '150.30',
            'calculation_rule_code' => 'test',
            'calculation_explanation' => 'Snapshot',
            'snapshot' => [
                'source' => 'Insurance Receivable',
                'date_of_death' => '2026-01-15',
                'loan_alt_account_number' => 'SNAP-ALT',
            ],
            ...$overrides,
        ]);
    }

    /**
     * @return list<string>
     */
    private function headers(): array
    {
        return [
            'No',
            'Cabang',
            'Jenis Piutang / Source',
            'CIF',
            'Nama Debitur',
            'No Rekening Kredit',
            'No Rekening Alternatif',
            'Perusahaan Asuransi',
            'Tanggal Meninggal',
            'Tanggal Pembentukan Piutang',
            'Status Klaim',
            'Keterangan Status Klaim',
            'Umur Piutang',
            'Nominal Piutang',
            'Sisa Piutang',
            'Faktor Asuransi',
            'Faktor Umur Piutang',
            'Faktor Status Klaim Digunakan',
            'Bobot CKPN',
            'Calculated CKPN',
            'Adjustment Delta',
            'Effective CKPN',
            'Catatan Adjustment',
        ];
    }

    /**
     * @return list<list<bool|\DateInterval|DateTimeInterface|float|int|string|null>>
     */
    private function rowsFromXlsx(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(
                    fn ($cell): bool|\DateInterval|DateTimeInterface|float|int|string|null => $cell->getValue(),
                    $row->getCells(),
                );
            }
        }

        $reader->close();

        return $rows;
    }

    private function userWithRole(string $role, string $branchCode): User
    {
        $branch = BranchOffice::query()->where('branch_code', $branchCode)->firstOrFail();
        $user = User::factory()->create(['branch_office_id' => $branch->id]);
        $user->assignRole($role);

        return $user;
    }
}
