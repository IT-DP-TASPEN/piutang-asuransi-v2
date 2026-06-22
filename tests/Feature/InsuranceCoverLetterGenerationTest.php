<?php

namespace Tests\Feature;

use App\Actions\InsuranceCoverLetter\GenerateInsuranceCoverLetterAction;
use App\Contracts\InsuranceCoverLetterPdfRenderer;
use App\Models\BranchOffice;
use App\Models\InsuranceCompany;
use App\Models\InsuranceCoverLetter;
use App\Models\InsuranceCoverLetterSetting;
use App\Models\InsuranceReceivable;
use App\Models\User;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimDocumentSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\InsuranceCoverLetterSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class InsuranceCoverLetterGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ajk_letter_is_dynamic_numbered_immutable_and_reused(): void
    {
        $this->seedDependencies();
        Storage::fake('local');
        InsuranceCoverLetterSetting::query()->findOrFail(1)->update(['sequence_base' => 500]);
        $user = User::factory()->create();
        $receivable = $this->receivableFor('TASPEN LIFE');

        $first = app(GenerateInsuranceCoverLetterAction::class)->handle($receivable, $user, '2026-06-21');

        $this->assertSame('ajk', $first->claim_type);
        $this->assertSame(500 + $first->id, $first->sequence_number);
        $this->assertSame("SRT – {$first->sequence_number}/B.01.1/062026", $first->letter_number);
        $this->assertSame('insurance-cover-letters.ajk', $first->template_key);
        $this->assertSame('PT ASURANSI JIWA TASPEN', $first->recipient_name);
        $this->assertStringContainsString('Jane Customer', $first->rendered_html);
        $this->assertStringContainsString('Formulir Pengajuan Klaim Asuransi', $first->rendered_html);
        $this->assertStringContainsString('src="/logo.png"', $first->rendered_html);
        $this->assertStringNotContainsString('base64,', $first->rendered_html);
        $this->assertNotNull($first->generated_file_path);
        Storage::disk('local')->assertExists($first->generated_file_path);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($first->generated_file_path));

        InsuranceCoverLetterSetting::query()->findOrFail(1)->update(['sequence_base' => 900]);
        $second = app(GenerateInsuranceCoverLetterAction::class)->handle($receivable->refresh(), $user, '2026-07-01');

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->letter_number, $second->letter_number);
        $this->assertSame($first->rendered_html, $second->rendered_html);
        $this->assertSame(1, InsuranceCoverLetter::query()->count());

        $this->expectException(\LogicException::class);
        $second->update(['letter_number' => 'changed']);
    }

    public function test_credit_claim_type_uses_credit_template_and_can_create_new_type_snapshot(): void
    {
        $this->seedDependencies();
        Storage::fake('local');
        $user = User::factory()->create();
        $receivable = $this->receivableFor('TASPEN LIFE');
        $ajk = app(GenerateInsuranceCoverLetterAction::class)->handle($receivable, $user);

        $creditCompany = InsuranceCompany::query()->where('name', 'ASEI')->firstOrFail();
        $receivable->update(['insurance_company_id' => $creditCompany->id]);
        $credit = app(GenerateInsuranceCoverLetterAction::class)->handle($receivable->refresh(), $user);

        $this->assertNotSame($ajk->id, $credit->id);
        $this->assertSame('credit', $credit->claim_type);
        $this->assertSame('insurance-cover-letters.credit', $credit->template_key);
        $this->assertStringContainsString('Formulir STGR', $credit->rendered_html);
        $this->assertStringContainsString('SLIK OJK', $credit->rendered_html);
        $this->assertStringNotContainsString('Formulir Pengajuan Klaim Asuransi', $credit->rendered_html);
    }

    public function test_pdf_failure_keeps_html_as_primary_output(): void
    {
        $this->seedDependencies();
        Storage::fake('local');
        $this->app->instance(InsuranceCoverLetterPdfRenderer::class, new class implements InsuranceCoverLetterPdfRenderer
        {
            public function render(string $html): string
            {
                throw new RuntimeException('PDF renderer unavailable.');
            }
        });

        $letter = app(GenerateInsuranceCoverLetterAction::class)->handle(
            $this->receivableFor('TASPEN LIFE'),
            User::factory()->create(),
        );

        $this->assertNotEmpty($letter->rendered_html);
        $this->assertNull($letter->generated_file_path);
        $this->assertSame(InsuranceCoverLetter::STATUS_GENERATED, $letter->status);
    }

    public function test_missing_template_returns_clear_validation_error(): void
    {
        $this->seedDependencies();
        config(['insurance_cover_letters.templates.ajk' => 'insurance-cover-letters.missing']);

        $this->expectException(ValidationException::class);
        app(GenerateInsuranceCoverLetterAction::class)->handle(
            $this->receivableFor('TASPEN LIFE'),
            User::factory()->create(),
        );
    }

    public function test_authenticated_preview_and_pdf_download_are_private(): void
    {
        $this->seedDependencies(withRoles: true);
        Storage::fake('local');
        $branch = BranchOffice::query()->where('branch_code', '000')->firstOrFail();
        $user = User::factory()->create(['branch_office_id' => $branch->id]);
        $user->assignRole('business_maker');
        $letter = app(GenerateInsuranceCoverLetterAction::class)->handle($this->receivableFor('TASPEN LIFE'), $user);

        $this->get(route('insurance-cover-letters.preview', $letter))->assertRedirect('/login');

        $this->actingAs($user)
            ->get(route('insurance-cover-letters.preview', $letter))
            ->assertOk()
            ->assertSee($letter->letter_number);
        $this->actingAs($user)
            ->get(route('insurance-cover-letters.download', $letter))
            ->assertOk()
            ->assertDownload("insurance-cover-letter-{$letter->id}.pdf");
    }

    private function seedDependencies(bool $withRoles = false): void
    {
        $seeders = [
            BranchOfficeSeeder::class,
            InsuranceCompanySeeder::class,
            ClaimStatusSeeder::class,
            ClaimDocumentSeeder::class,
            InsuranceCoverLetterSettingSeeder::class,
        ];

        if ($withRoles) {
            $seeders[] = RolePermissionSeeder::class;
        }

        $this->seed($seeders);
    }

    private function receivableFor(string $companyName): InsuranceReceivable
    {
        return InsuranceReceivable::factory()->create([
            'insurance_company_id' => InsuranceCompany::query()->where('name', $companyName)->firstOrFail()->id,
            'customer_name' => 'Jane Customer',
            'cif_no' => 'CIF-001',
            'loan_account_number' => '3010010000000068',
            'date_of_death' => '2026-01-15',
            'death_document_condition' => 'hospital',
            'credit_limit' => '250000000.00',
            'loan_outstanding' => '230929055.00',
            'receivable_amount' => '230929055.00',
            'start_period' => '2025-01-01',
            'end_period' => '2030-01-01',
        ]);
    }
}
