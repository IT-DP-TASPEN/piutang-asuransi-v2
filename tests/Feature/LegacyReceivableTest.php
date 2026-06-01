<?php

namespace Tests\Feature;

use App\Actions\LegacyReceivable\DeleteLegacyReceivablePaymentAction;
use App\Actions\LegacyReceivable\PrepareLegacyReceivableDataAction;
use App\Actions\LegacyReceivable\RecordLegacyReceivablePaymentAction;
use App\Actions\LegacyReceivable\UpdateLegacyReceivablePaymentAction;
use App\Models\BranchOffice;
use App\Models\ClaimStatus;
use App\Models\InsuranceCompany;
use App\Models\LegacyReceivable;
use App\Models\User;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LegacyReceivableTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_receivable_can_be_created_with_master_references_and_default_claim_status(): void
    {
        $this->seedDependencies();
        $user = $this->userWithRole('accounting_maker', '000');
        $branch = BranchOffice::query()->where('branch_code', '001')->firstOrFail();
        $insuranceCompany = InsuranceCompany::query()->where('name', 'SDI')->firstOrFail();

        $data = app(PrepareLegacyReceivableDataAction::class)->handle([
            'cif' => 'CIF-LEG-1',
            'customer_name' => 'Legacy Customer',
            'loan_account_number' => '3010001000054745',
            'branch_office_id' => $branch->id,
            'loan_outstanding' => '10000.00',
            'insurance_company_id' => $insuranceCompany->id,
            'date_of_death' => '2026-01-01',
            'original_receivable_amount' => '10000.00',
        ], $user);

        $legacy = LegacyReceivable::query()->create($data);

        $this->assertSame($branch->id, $legacy->branch_office_id);
        $this->assertSame($insuranceCompany->id, $legacy->insurance_company_id);
        $this->assertSame(ClaimStatus::DEFAULT_CODE, $legacy->claimStatus->code);
        $this->assertSame('10000.00', $legacy->remaining_receivable_amount);
        $this->assertSame($user->id, $legacy->created_by);
    }

    public function test_payment_create_update_and_delete_recalculates_remaining_amount(): void
    {
        $this->seedDependencies();
        $user = $this->userWithRole('accounting_maker', '000');
        $legacy = LegacyReceivable::factory()->create([
            'original_receivable_amount' => '10000.00',
            'remaining_receivable_amount' => '10000.00',
        ]);

        $payment = app(RecordLegacyReceivablePaymentAction::class)->handle($legacy, [
            'amount' => '2500.00',
            'paid_at' => '2026-06-01',
        ], $user);

        $this->assertSame('7500.00', $legacy->refresh()->remaining_receivable_amount);

        app(UpdateLegacyReceivablePaymentAction::class)->handle($payment, [
            'amount' => '3000.00',
            'paid_at' => '2026-06-01',
        ]);

        $this->assertSame('7000.00', $legacy->refresh()->remaining_receivable_amount);

        app(DeleteLegacyReceivablePaymentAction::class)->handle($payment->refresh());

        $this->assertSame('10000.00', $legacy->refresh()->remaining_receivable_amount);
    }

    public function test_payment_amount_cannot_exceed_remaining_receivable(): void
    {
        $this->seedDependencies();
        $user = $this->userWithRole('accounting_maker', '000');
        $legacy = LegacyReceivable::factory()->create([
            'original_receivable_amount' => '10000.00',
            'remaining_receivable_amount' => '10000.00',
        ]);

        $this->expectException(ValidationException::class);

        app(RecordLegacyReceivablePaymentAction::class)->handle($legacy, [
            'amount' => '10000.01',
            'paid_at' => '2026-06-01',
        ], $user);
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
}
