<?php

namespace Tests\Feature;

use App\Actions\ClaimStatusChangeRequest\CreateAndSubmitClaimStatusChangeFromReceivableAction;
use App\Actions\InsuranceCoverLetter\GenerateInsuranceCoverLetterAction;
use App\Actions\InsuranceReceivable\QueueEarlyTerminationAction;
use App\Actions\InsuranceReceivable\StoreClaimDocumentAction;
use App\Actions\InsuranceReceivable\SubmitInsuranceReceivableForApprovalAction;
use App\Actions\ReceivablePayment\RecordReceivablePaymentAction;
use App\Models\BranchOffice;
use App\Models\ClaimStatus;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\InsuranceReceivable\InsuranceReceivableInquiryDispatcher;
use Database\Seeders\BranchOfficeSeeder;
use Database\Seeders\ClaimStatusSeeder;
use Database\Seeders\InsuranceCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InsuranceReceivableOriginTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_origin_uses_imported_formed_state_and_preserves_explicit_remaining_amount(): void
    {
        $this->seedDependencies();

        $fullyPaid = InsuranceReceivable::factory()->legacy()->create([
            'receivable_amount' => '10000.00',
            'remaining_receivable_amount' => '0.00',
        ]);
        $defaulted = InsuranceReceivable::factory()->legacy()->create([
            'receivable_amount' => '15000.00',
        ]);

        $this->assertTrue($fullyPaid->isLegacyOrigin());
        $this->assertSame(InsuranceReceivable::ORIGIN_TYPE_LEGACY, $fullyPaid->origin_type);
        $this->assertSame(InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED, $fullyPaid->workflow_status);
        $this->assertSame(InsuranceReceivable::SYSTEM_STATUS_LEGACY_IMPORTED, $fullyPaid->system_status);
        $this->assertSame('0.00', $fullyPaid->remaining_receivable_amount);
        $this->assertSame('15000.00', $defaulted->remaining_receivable_amount);
    }

    public function test_legacy_origin_can_record_payment_and_use_claim_status_flow(): void
    {
        $this->seedDependencies();
        $user = $this->userWithRole('business_maker', '000');
        $legacy = InsuranceReceivable::factory()->legacy()->create([
            'receivable_amount' => '10000.00',
            'remaining_receivable_amount' => '10000.00',
        ]);
        $toStatus = ClaimStatus::query()
            ->where('code', 'approved')
            ->firstOrFail();

        $payment = app(RecordReceivablePaymentAction::class)->handle($legacy, [
            'amount' => '2500.00',
            'paid_at' => '2026-06-01',
        ], $user);
        $request = app(CreateAndSubmitClaimStatusChangeFromReceivableAction::class)->handle($legacy->refresh(), $user, [
            'to_claim_status_id' => $toStatus->id,
            'reason' => 'Claim accepted by insurer.',
        ]);

        $this->assertSame($legacy->id, $payment->insurance_receivable_id);
        $this->assertSame('7500.00', $legacy->refresh()->remaining_receivable_amount);
        $this->assertSame($legacy->id, $request->insurance_receivable_id);
        $this->assertSame($toStatus->id, $request->to_claim_status_id);
    }

    public function test_legacy_origin_is_hard_blocked_from_workflow_inquiry_et_documents_cover_letter_and_direct_edit(): void
    {
        $this->seedDependencies();
        $user = $this->userWithRole('branch_maker', '001');
        $legacy = InsuranceReceivable::factory()->legacy()->create();

        $this->assertFalse($user->can('update', $legacy));
        $this->assertValidationBlocked(fn () => app(InsuranceReceivableInquiryDispatcher::class)->dispatch($legacy));
        $this->assertValidationBlocked(fn () => app(SubmitInsuranceReceivableForApprovalAction::class)->handle($legacy, $user));
        $this->assertValidationBlocked(fn () => app(QueueEarlyTerminationAction::class)->handle($legacy, $user));
        $this->assertValidationBlocked(fn () => app(StoreClaimDocumentAction::class)->handle($legacy, 1, 'claims/test.pdf', 'test.pdf', $user));
        $this->assertValidationBlocked(fn () => app(GenerateInsuranceCoverLetterAction::class)->handle($legacy, $user, '2026-06-01'));
    }

    private function assertValidationBlocked(callable $callback): void
    {
        try {
            $callback();

            $this->fail('Legacy-origin guard should reject this action.');
        } catch (ValidationException) {
        }
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
