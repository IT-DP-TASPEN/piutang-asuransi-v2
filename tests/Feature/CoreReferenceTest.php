<?php

namespace Tests\Feature;

use App\Actions\InsuranceReceivable\ExecuteEarlyTerminationAction;
use App\Models\CoreTransactionReference;
use App\Models\InsuranceReceivable;
use App\Services\CoreBanking\CoreTransactionReferenceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CoreReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_core_reference_registry_rejects_duplicate_reference(): void
    {
        $registry = app(CoreTransactionReferenceRegistry::class);

        $registry->reserve('GLOBAL-REF-1', 'gl_to_gl:test', 'op-1');

        $this->expectException(ValidationException::class);

        $registry->reserve('GLOBAL-REF-1', 'early_termination', 'op-2');
    }

    public function test_core_reference_is_not_reserved_when_validation_fails_before_http(): void
    {
        Http::fake();

        $receivable = InsuranceReceivable::factory()->create([
            'loan_outstanding' => null,
            'workflow_status' => InsuranceReceivable::WORKFLOW_STATUS_RECEIVABLE_FORMED,
        ]);

        try {
            app(ExecuteEarlyTerminationAction::class)->handle($receivable);
            $this->fail('Expected validation failure.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('core_transaction_references', 0);
            Http::assertNothingSent();
        }
    }

    public function test_core_reference_registry_stores_reserved_reference(): void
    {
        app(CoreTransactionReferenceRegistry::class)->reserve('GLOBAL-REF-2', 'loan_repayment', 'op-3');

        $this->assertSame('GLOBAL-REF-2', CoreTransactionReference::query()->sole()->reference);
    }
}
