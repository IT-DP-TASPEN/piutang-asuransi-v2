<?php

namespace Tests\Feature;

use App\Models\BranchOffice;
use App\Models\CkpnWorkpaper;
use App\Models\ClaimDocumentType;
use App\Models\GeneratedExport;
use App\Models\InsuranceReceivable;
use App\Models\InsuranceReceivableDocument;
use App\Models\User;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimDocumentSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivateDownloadRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_download_insurance_receivable_attachment(): void
    {
        $this->seedDependencies();
        Storage::fake(InsuranceReceivableDocument::DISK);
        $maker = $this->userWithRole('branch_maker', '001');
        $receivable = $this->receivableFor($maker);
        $document = $this->documentFor($receivable, $maker);
        Storage::disk(InsuranceReceivableDocument::DISK)->put($document->file_path, 'document-content');

        $this->actingAs($maker)
            ->get(route('insurance-receivable-documents.download', $document))
            ->assertOk()
            ->assertDownload($document->original_file_name);
    }

    public function test_unauthorized_user_cannot_download_insurance_receivable_attachment(): void
    {
        $this->seedDependencies();
        Storage::fake(InsuranceReceivableDocument::DISK);
        $owner = $this->userWithRole('branch_maker', '001');
        $other = $this->userWithRole('branch_maker', '002');
        $document = $this->documentFor($this->receivableFor($owner), $owner);
        Storage::disk(InsuranceReceivableDocument::DISK)->put($document->file_path, 'document-content');

        $this->actingAs($other)
            ->get(route('insurance-receivable-documents.download', $document))
            ->assertForbidden();
    }

    public function test_authorized_user_can_download_generated_export(): void
    {
        $this->seedDependencies();
        Storage::fake(GeneratedExport::DISK);
        $user = $this->userWithRole('accounting_maker', '000');
        $export = $this->generatedExport();
        Storage::disk(GeneratedExport::DISK)->put($export->file_path, 'export-content');

        $this->actingAs($user)
            ->get(route('generated-exports.download', $export))
            ->assertOk()
            ->assertDownload(basename($export->file_path));
    }

    public function test_unauthorized_user_cannot_download_generated_export(): void
    {
        $this->seedDependencies();
        Storage::fake(GeneratedExport::DISK);
        $otherBranchUser = $this->userWithRole('branch_maker', '002');
        $export = $this->generatedExport('001');
        Storage::disk(GeneratedExport::DISK)->put($export->file_path, 'export-content');

        $this->actingAs($otherBranchUser)
            ->get(route('generated-exports.download', $export))
            ->assertForbidden();
    }

    public function test_missing_private_file_returns_not_found(): void
    {
        $this->seedDependencies();
        Storage::fake(GeneratedExport::DISK);
        $user = $this->userWithRole('accounting_maker', '000');
        $export = $this->generatedExport();

        $this->actingAs($user)
            ->get(route('generated-exports.download', $export))
            ->assertNotFound();
    }

    private function seedDependencies(): void
    {
        $this->seed([
            BranchOfficeSeeder::class,
            InsuranceCompanySeeder::class,
            ClaimStatusSeeder::class,
            ClaimDocumentSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    private function receivableFor(User $user): InsuranceReceivable
    {
        return InsuranceReceivable::factory()->create([
            'branch_office_id' => $user->branch_office_id,
            'branch_code' => $user->branchOffice->branch_code,
            'created_by' => $user->id,
        ]);
    }

    private function documentFor(InsuranceReceivable $receivable, User $user): InsuranceReceivableDocument
    {
        $type = ClaimDocumentType::query()->where('code', 'insurance_certificate_copy')->firstOrFail();

        return $receivable->documents()->create([
            'claim_document_type_id' => $type->id,
            'file_path' => "testing/{$receivable->id}-certificate.pdf",
            'original_file_name' => 'certificate.pdf',
            'mime_type' => 'application/pdf',
            'uploaded_by' => $user->id,
            'uploaded_at' => now(),
        ]);
    }

    private function generatedExport(string $branchCode = '001'): GeneratedExport
    {
        $branch = BranchOffice::query()->where('branch_code', $branchCode)->firstOrFail();
        $workpaper = CkpnWorkpaper::query()->create([
            'period' => '2026-05-01',
            'branch_office_id' => $branch->id,
            'status' => CkpnWorkpaper::STATUS_APPROVED,
        ]);

        return $workpaper->generatedExports()->create([
            'export_type' => GeneratedExport::TYPE_CKPN_WORKPAPER_SAKEP_XLSX,
            'file_path' => "generated-exports/ckpn-workpapers/workpaper-{$branchCode}.xlsx",
            'status' => GeneratedExport::STATUS_GENERATED,
        ]);
    }

    private function userWithRole(string $role, string $branchCode): User
    {
        $branchOffice = BranchOffice::query()->where('branch_code', $branchCode)->firstOrFail();
        $user = User::factory()->create(['branch_office_id' => $branchOffice->id]);
        $user->assignRole($role);

        return $user;
    }
}
