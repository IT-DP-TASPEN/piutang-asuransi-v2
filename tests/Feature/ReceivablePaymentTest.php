<?php

namespace Tests\Feature;

use App\Actions\ReceivablePayment\RecordReceivablePaymentAction;
use App\Filament\Resources\InsuranceReceivables\InsuranceReceivableResource;
use App\Filament\Resources\InsuranceReceivables\Pages\ViewInsuranceReceivable;
use App\Filament\Resources\RelationManagers\ReceivablePaymentsRelationManager;
use App\Models\BranchOffice;
use App\Models\InsuranceReceivable;
use App\Models\ReceivablePayment;
use App\Models\User;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ReceivablePaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_record_legacy_payment_and_decrease_remaining_amount(): void
    {
        $this->seedDependencies();
        $user = $this->userWithRole('accounting_maker', '000');
        $legacy = InsuranceReceivable::factory()->legacy()->create([
            'receivable_amount' => '10000.00',
            'remaining_receivable_amount' => '10000.00',
        ]);

        $payment = app(RecordReceivablePaymentAction::class)->handle($legacy, [
            'amount' => '2500.00',
            'paid_at' => '2026-06-01',
        ], $user);

        $this->assertSame($legacy->id, $payment->insurance_receivable_id);
        $this->assertSame($user->id, $payment->created_by);
        $this->assertSame('7500.00', $legacy->refresh()->remaining_receivable_amount);
    }

    public function test_legacy_overpayment_and_soft_deleted_receivable_are_rejected(): void
    {
        $this->seedDependencies();
        $user = $this->userWithRole('accounting_maker', '000');
        $legacy = InsuranceReceivable::factory()->legacy()->create([
            'receivable_amount' => '10000.00',
            'remaining_receivable_amount' => '10000.00',
        ]);

        try {
            app(RecordReceivablePaymentAction::class)->handle($legacy, [
                'amount' => '10000.01',
                'paid_at' => '2026-06-01',
            ], $user);

            $this->fail('Legacy-origin overpayment should be rejected.');
        } catch (ValidationException) {
        }

        $legacy->delete();

        try {
            app(RecordReceivablePaymentAction::class)->handle($legacy, [
                'amount' => '100.00',
                'paid_at' => '2026-06-01',
            ], $user);

            $this->fail('Payment for deleted legacy-origin receivable should be rejected.');
        } catch (ValidationException) {
        }
    }

    public function test_can_record_insurance_payment_for_allowed_statuses(): void
    {
        $this->seedDependencies();
        $user = $this->userWithRole('accounting_maker', '000');

        foreach ([
            InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
            InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_EXECUTED,
            InsuranceReceivable::WORKFLOW_STATUS_EARLY_TERMINATION_RESOLVED,
        ] as $status) {
            $receivable = $this->insuranceReceivable($status);

            $payment = app(RecordReceivablePaymentAction::class)->handle($receivable, [
                'amount' => '2500.00',
                'paid_at' => '2026-06-01',
            ], $user);

            $this->assertSame($receivable->id, $payment->insurance_receivable_id);
            $this->assertSame('7500.00', $receivable->refresh()->remaining_receivable_amount);
        }
    }

    public function test_insurance_payment_rejects_disallowed_statuses_and_overpayment(): void
    {
        $this->seedDependencies();
        $user = $this->userWithRole('accounting_maker', '000');

        foreach ([
            InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
            InsuranceReceivable::WORKFLOW_STATUS_REJECTED,
            InsuranceReceivable::WORKFLOW_STATUS_CANCELLED,
        ] as $status) {
            $receivable = $this->insuranceReceivable($status);

            try {
                app(RecordReceivablePaymentAction::class)->handle($receivable, [
                    'amount' => '100.00',
                    'paid_at' => '2026-06-01',
                ], $user);

                $this->fail("Insurance payment should be rejected for {$status}.");
            } catch (ValidationException) {
            }

            $this->assertSame('10000.00', $receivable->refresh()->remaining_receivable_amount);
        }

        $receivable = $this->insuranceReceivable(InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED);

        $this->expectException(ValidationException::class);

        app(RecordReceivablePaymentAction::class)->handle($receivable, [
            'amount' => '10000.01',
            'paid_at' => '2026-06-01',
        ], $user);
    }

    public function test_payment_requires_insurance_receivable_target(): void
    {
        $this->seedDependencies();
        $user = $this->userWithRole('accounting_maker', '000');
        $base = [
            'amount' => '100.00',
            'paid_at' => '2026-06-01',
            'created_by' => $user->id,
        ];

        try {
            ReceivablePayment::query()->create($base);

            $this->fail('Payment without target should be rejected.');
        } catch (ValidationException) {
        }
    }

    public function test_recorded_payment_cannot_be_updated_or_deleted(): void
    {
        $this->seedDependencies();
        $user = $this->userWithRole('accounting_maker', '000');
        $legacy = InsuranceReceivable::factory()->legacy()->create();
        $payment = app(RecordReceivablePaymentAction::class)->handle($legacy, [
            'amount' => '100.00',
            'paid_at' => '2026-06-01',
        ], $user);

        try {
            $payment->forceFill(['amount' => '200.00'])->save();

            $this->fail('Recorded payment update should be rejected.');
        } catch (ValidationException) {
        }

        try {
            $payment->delete();

            $this->fail('Recorded payment delete should be rejected.');
        } catch (ValidationException) {
        }
    }

    public function test_payment_relation_manager_is_create_only_for_legacy_and_workflow_origins(): void
    {
        $this->seedDependencies();
        $user = $this->userWithRole('accounting_maker', '000');
        $legacy = InsuranceReceivable::factory()->legacy()->create();
        $insurance = $this->insuranceReceivable(InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED);

        $this->assertContains(ReceivablePaymentsRelationManager::class, InsuranceReceivableResource::getRelations());

        Livewire::actingAs($user)
            ->test(ReceivablePaymentsRelationManager::class, [
                'ownerRecord' => $legacy,
                'pageClass' => ViewInsuranceReceivable::class,
            ])
            ->assertTableHeaderActionsExistInOrder(['create'])
            ->assertTableActionsExistInOrder([]);

        Livewire::actingAs($user)
            ->test(ReceivablePaymentsRelationManager::class, [
                'ownerRecord' => $insurance,
                'pageClass' => ViewInsuranceReceivable::class,
            ])
            ->assertTableHeaderActionsExistInOrder(['create'])
            ->assertTableActionsExistInOrder([]);
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

    private function insuranceReceivable(string $workflowStatus): InsuranceReceivable
    {
        return InsuranceReceivable::factory()->create([
            'workflow_status' => $workflowStatus,
            'receivable_formation_date' => '2026-01-01',
            'receivable_amount' => '10000.00',
            'remaining_receivable_amount' => '10000.00',
        ]);
    }
}
