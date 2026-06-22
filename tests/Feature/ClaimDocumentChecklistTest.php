<?php

namespace Tests\Feature;

use App\Actions\InsuranceReceivable\StoreClaimDocumentAction;
use App\Data\ClaimDocuments\ClaimDocumentChecklistItem;
use App\Filament\Resources\InsuranceReceivables\Pages\ViewInsuranceReceivable;
use App\Filament\Resources\InsuranceReceivables\RelationManagers\DocumentsRelationManager;
use App\Models\BranchOffice;
use App\Models\ClaimDocumentType;
use App\Models\InsuranceCompany;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\InsuranceReceivable\ResolveClaimDocumentChecklist;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimDocumentSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClaimDocumentChecklistTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_seed_contains_required_claim_and_recipient_data(): void
    {
        $this->seedDependencies();

        $this->assertSame('ajk', InsuranceCompany::query()->where('name', 'SDI')->value('claim_type'));
        $this->assertSame('ajk', InsuranceCompany::query()->where('name', 'AA PIALANG')->value('claim_type'));
        $this->assertSame('ajk', InsuranceCompany::query()->where('name', 'MPM')->value('claim_type'));
        $this->assertSame('credit', InsuranceCompany::query()->where('name', 'MNC ASURANSI')->value('claim_type'));
        $this->assertDatabaseHas('insurance_companies', ['name' => 'VICTORIA ALIFE', 'ckpn_weight' => 0]);
        $this->assertDatabaseHas('insurance_companies', ['name' => 'MNC ASURANSI', 'ckpn_weight' => 0]);

        $sdi = InsuranceCompany::query()->where('name', 'SDI')->firstOrFail();
        $this->assertSame('SDI', $sdi->resolvedLetterRecipientName());
    }

    public function test_date_of_death_is_synthetic_and_never_a_document_type(): void
    {
        $this->seedDependencies();
        $receivable = InsuranceReceivable::factory()->create(['date_of_death' => null]);
        $checklist = app(ResolveClaimDocumentChecklist::class)->handle($receivable);
        $dateItem = collect($checklist->items)->firstWhere('code', 'date_of_death');

        $this->assertDatabaseMissing('claim_document_types', ['code' => 'date_of_death']);
        $this->assertNotNull($dateItem);
        $this->assertFalse($dateItem->uploadable);
        $this->assertSame(ClaimDocumentChecklistItem::STATUS_MISSING_DATA, $dateItem->status);
        $this->assertContains('Tanggal Debitur Meninggal belum diisi.', $checklist->missingDataWarnings);

        $receivable->update(['date_of_death' => '2026-01-15']);
        $complete = app(ResolveClaimDocumentChecklist::class)->handle($receivable->refresh());
        $this->assertSame(
            ClaimDocumentChecklistItem::STATUS_COMPLETE,
            collect($complete->items)->firstWhere('code', 'date_of_death')->status,
        );
    }

    public function test_ajk_and_credit_matrix_are_resolved_from_master_requirements(): void
    {
        $this->seedDependencies();

        $ajk = $this->receivableFor('TASPEN LIFE');
        $ajkChecklist = app(ResolveClaimDocumentChecklist::class)->handle($ajk);
        $ajkCodes = collect($ajkChecklist->items)->pluck('code');
        $this->assertTrue($ajkCodes->contains('claim_submission_form'));
        $this->assertFalse($ajkCodes->contains('stgr_form'));
        $this->assertFalse($ajkCodes->contains('slik_ojk'));
        $this->assertSame(10, $ajkChecklist->requiredUploadCount);

        $credit = $this->receivableFor('ASEI');
        $creditChecklist = app(ResolveClaimDocumentChecklist::class)->handle($credit);
        $creditCodes = collect($creditChecklist->items)->pluck('code');
        $this->assertFalse($creditCodes->contains('claim_submission_form'));
        $this->assertTrue($creditCodes->contains('stgr_form'));
        $this->assertTrue($creditCodes->contains('slik_ojk'));
        $this->assertSame(11, $creditChecklist->requiredUploadCount);

        foreach (['insurance_certificate_copy', 'debtor_id_card', 'credit_agreement', 'account_statement'] as $commonCode) {
            $this->assertTrue($ajkCodes->contains($commonCode));
            $this->assertTrue($creditCodes->contains($commonCode));
        }
    }

    public function test_each_death_condition_selects_only_its_matching_document(): void
    {
        $this->seedDependencies();
        $receivable = $this->receivableFor('TASPEN LIFE');
        $conditionToCode = [
            'hospital' => 'death_certificate_hospital',
            'accident' => 'death_certificate_police',
            'overseas' => 'death_certificate_embassy',
            'home' => 'death_certificate_local_authority',
            'civil_registry' => 'death_certificate_civil_registry',
        ];

        $pending = app(ResolveClaimDocumentChecklist::class)->handle($receivable);
        $this->assertCount(5, collect($pending->items)->where('status', ClaimDocumentChecklistItem::STATUS_PENDING_CONDITION));
        $this->assertStringContainsString('Death document condition', implode(' ', $pending->warnings));

        foreach ($conditionToCode as $condition => $selectedCode) {
            $receivable->update(['death_document_condition' => $condition]);
            $checklist = app(ResolveClaimDocumentChecklist::class)->handle($receivable->refresh());
            $conditional = collect($checklist->items)->where('conditional', true);

            $this->assertSame(
                ClaimDocumentChecklistItem::STATUS_MISSING,
                $conditional->firstWhere('code', $selectedCode)->status,
            );
            $this->assertCount(4, $conditional->where('status', ClaimDocumentChecklistItem::STATUS_NOT_APPLICABLE));
        }
    }

    public function test_upload_replace_uses_one_row_and_updates_resolver_progress(): void
    {
        $this->seedDependencies();
        $user = User::factory()->create();
        $receivable = $this->receivableFor('TASPEN LIFE', ['death_document_condition' => 'hospital']);
        $type = ClaimDocumentType::query()->where('code', 'insurance_certificate_copy')->firstOrFail();

        app(StoreClaimDocumentAction::class)->handle($receivable, $type->id, 'claims/first.pdf', 'first.pdf', $user);
        app(StoreClaimDocumentAction::class)->handle($receivable->refresh(), $type->id, 'claims/replacement.pdf', 'replacement.pdf', $user);

        $this->assertSame(1, $receivable->documents()->where('claim_document_type_id', $type->id)->count());
        $this->assertDatabaseHas('insurance_receivable_documents', [
            'insurance_receivable_id' => $receivable->id,
            'claim_document_type_id' => $type->id,
            'file_path' => 'claims/replacement.pdf',
            'original_file_name' => 'replacement.pdf',
        ]);
        $this->assertSame(1, app(ResolveClaimDocumentChecklist::class)->handle($receivable->refresh())->uploadedCount);
    }

    public function test_filament_checklist_consumes_resolver_records(): void
    {
        $this->seedDependencies(withRoles: true);
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $user = User::factory()->create(['branch_office_id' => $branch->id]);
        $user->assignRole('branch_maker');
        $receivable = InsuranceReceivable::factory()->create([
            'branch_office_id' => $branch->id,
            'branch_code' => $branch->branch_code,
            'insurance_company_id' => InsuranceCompany::query()->where('name', 'TASPEN LIFE')->firstOrFail()->id,
            'created_by' => $user->id,
            'date_of_death' => '2026-01-15',
        ]);

        Livewire::actingAs($user)
            ->test(DocumentsRelationManager::class, [
                'ownerRecord' => $receivable,
                'pageClass' => ViewInsuranceReceivable::class,
            ])
            ->assertSee('0/10 documents uploaded')
            ->assertSee('Tanggal Debitur Meninggal')
            ->assertSee('Formulir Pengajuan Klaim Asuransi');
    }

    private function seedDependencies(bool $withRoles = false): void
    {
        $seeders = [
            BranchOfficeSeeder::class,
            InsuranceCompanySeeder::class,
            ClaimStatusSeeder::class,
            ClaimDocumentSeeder::class,
        ];

        if ($withRoles) {
            $seeders[] = RolePermissionSeeder::class;
        }

        $this->seed($seeders);
    }

    /** @param array<string, mixed> $attributes */
    private function receivableFor(string $companyName, array $attributes = []): InsuranceReceivable
    {
        return InsuranceReceivable::factory()->create([
            'insurance_company_id' => InsuranceCompany::query()->where('name', $companyName)->firstOrFail()->id,
            'date_of_death' => '2026-01-15',
            ...$attributes,
        ]);
    }
}
