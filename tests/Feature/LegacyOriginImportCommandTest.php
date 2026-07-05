<?php

namespace Tests\Feature;

use App\Models\BranchOffice;
use App\Models\ClaimStatus;
use App\Models\InsuranceCompany;
use App\Models\InsuranceReceivable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyOriginImportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_legacy_origin_insurance_receivables_from_csv(): void
    {
        $this->seedMasters();

        $path = $this->csv([
            "\xEF\xBB\xBFcif; customer_name ;loan_account_number;loan_alt_account_number; loan_outstanding ;insurance_company;date_of_death;receivable_formation_date; original_receivable_amount ; remaining_receivable_amount ;claim_status; branch_office ",
            ' CIF0001 ; Customer One ;0130100455;0130100456; 35.018.958 ; TASPEN LIFE ;2023-03-31;2023-04-01; 35.018.958 ; 0 ; ON PROSES ;001',
            ' CIF0002 ; Customer Two ;0130100457;; 16.826.662 ; TASPEN LIFE ;2023-05-31;; 16.826.662 ; ; ON PROSES ;001',
        ]);

        $this->artisan('insurance-receivables:import-legacy', ['path' => $path])
            ->assertExitCode(Command::SUCCESS);

        $first = InsuranceReceivable::query()->where('cif_no', 'CIF0001')->firstOrFail();
        $second = InsuranceReceivable::query()->where('cif_no', 'CIF0002')->firstOrFail();

        $this->assertSame(InsuranceReceivable::ORIGIN_TYPE_LEGACY, $first->origin_type);
        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED, $first->workflow_status);
        $this->assertSame(InsuranceReceivable::SYSTEM_STATUS_LEGACY_IMPORTED, $first->system_status);
        $this->assertSame('CIF0001', $first->cif_no);
        $this->assertSame('Customer One', $first->customer_name);
        $this->assertSame('0130100455', $first->loan_account_number);
        $this->assertSame('0130100456', $first->alt_number);
        $this->assertSame('35018958.00', $first->loan_outstanding);
        $this->assertSame('35018958.00', $first->receivable_amount);
        $this->assertSame('0.00', $first->remaining_receivable_amount);
        $this->assertSame('2023-04-01', $first->receivable_formation_date?->toDateString());
        $this->assertSame(BranchOffice::query()->where('branch_code', '001')->value('id'), $first->branch_office_id);
        $this->assertSame(InsuranceCompany::query()->where('name', 'Taspen Life')->value('id'), $first->insurance_company_id);
        $this->assertSame(ClaimStatus::query()->where('name', 'On Proses')->value('id'), $first->claim_status_id);
        $this->assertNull($first->created_by);

        $this->assertSame('16826662.00', $second->receivable_amount);
        $this->assertSame('16826662.00', $second->remaining_receivable_amount);
        $this->assertSame('2023-05-31', $second->receivable_formation_date?->toDateString());
        $this->assertSame(0, $first->approvalRequests()->count());
    }

    public function test_missing_foreign_key_rolls_back_every_insert(): void
    {
        $this->seedMasters();

        $path = $this->csv([
            'cif;customer_name;loan_account_number;loan_alt_account_number;loan_outstanding;insurance_company;date_of_death;receivable_formation_date;original_receivable_amount;remaining_receivable_amount;claim_status;branch_office',
            'CIF0001;Customer One;0130100455;;35.018.958;TASPEN LIFE;2023-03-31;2023-04-01;35.018.958;5;ON PROSES;001',
            'CIF0002;Customer Two;0130100457;;16.826.662;MISSING INSURANCE;2025-05-26;2025-05-26;16.826.662;16.826.662;ON PROSES;001',
        ]);

        $this->artisan('insurance-receivables:import-legacy', ['path' => $path])
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('insurance_receivables', 0);
    }

    private function seedMasters(): void
    {
        BranchOffice::create([
            'branch_code' => '001',
            'branch_name' => 'Cabang 001',
            'is_active' => true,
        ]);

        InsuranceCompany::create([
            'name' => 'Taspen Life',
            'claim_type' => InsuranceCompany::CLAIM_TYPE_AJK,
            'ckpn_weight' => '100',
            'is_active' => true,
        ]);

        ClaimStatus::create([
            'code' => ClaimStatus::DEFAULT_CODE,
            'name' => 'On Proses',
            'ckpn_weight' => '0',
            'is_default' => true,
            'is_terminal' => false,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function csv(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ir_legacy_origin_');

        file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }
}
