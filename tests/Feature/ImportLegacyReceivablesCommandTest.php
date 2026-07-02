<?php

namespace Tests\Feature;

use App\Models\BranchOffice;
use App\Models\ClaimStatus;
use App\Models\InsuranceCompany;
use App\Models\LegacyReceivable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportLegacyReceivablesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_legacy_receivables_from_csv(): void
    {
        $this->seedMasters();

        $path = $this->csv([
            "\xEF\xBB\xBFcif; customer_name ;loan_account_number;loan_alt_account_number; loan_outstanding ;insurance_company;date_of_death;receivable_formation_date; original_receivable_amount ; remaining_receivable_amount ;claim_status; branch_office ",
            ' CIF0001 ; Customer One ;0130100455;0130100456; 35.018.958 ; TASPEN LIFE ;2023-03-31;2023-04-01; 35.018.958 ; 5 ; ON PROSES ;001',
        ]);

        $this->artisan('legacy-receivables:import', ['path' => $path])
            ->assertExitCode(Command::SUCCESS);

        $legacy = LegacyReceivable::query()->firstOrFail();

        $this->assertSame('CIF0001', $legacy->cif);
        $this->assertSame('Customer One', $legacy->customer_name);
        $this->assertSame('0130100455', $legacy->loan_account_number);
        $this->assertSame('0130100456', $legacy->loan_alt_account_number);
        $this->assertSame('35018958.00', $legacy->loan_outstanding);
        $this->assertSame('35018958.00', $legacy->original_receivable_amount);
        $this->assertSame('5.00', $legacy->remaining_receivable_amount);
        $this->assertSame(BranchOffice::query()->where('branch_code', '001')->value('id'), $legacy->branch_office_id);
        $this->assertSame(InsuranceCompany::query()->where('name', 'Taspen Life')->value('id'), $legacy->insurance_company_id);
        $this->assertSame(ClaimStatus::query()->where('name', 'On Proses')->value('id'), $legacy->claim_status_id);
        $this->assertNull($legacy->created_by);
    }

    public function test_missing_foreign_key_rolls_back_every_insert(): void
    {
        $this->seedMasters();

        $path = $this->csv([
            'cif;customer_name;loan_account_number;loan_alt_account_number;loan_outstanding;insurance_company;date_of_death;receivable_formation_date;original_receivable_amount;remaining_receivable_amount;claim_status;branch_office',
            'CIF0001;Customer One;0130100455;;35.018.958;TASPEN LIFE;2023-03-31;2023-04-01;35.018.958;5;ON PROSES;001',
            'CIF0002;Customer Two;0130100457;;16.826.662;MISSING INSURANCE;2025-05-26;2025-05-26;16.826.662;16.826.662;ON PROSES;001',
        ]);

        $this->artisan('legacy-receivables:import', ['path' => $path])
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('legacy_receivables', 0);
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
        $path = tempnam(sys_get_temp_dir(), 'legacy_receivables_');

        file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }
}
