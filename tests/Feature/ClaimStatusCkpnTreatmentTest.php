<?php

namespace Tests\Feature;

use App\Models\ClaimStatus;
use App\Services\Ckpn\ResolveClaimStatusCkpnTreatment;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ClaimStatusCkpnTreatmentTest extends TestCase
{
    public function test_resolver_returns_expected_treatment_for_all_decision_statuses(): void
    {
        $resolver = app(ResolveClaimStatusCkpnTreatment::class);

        $this->assertTreatment($resolver, ClaimStatus::APPROVED_CODE, '1000.00', '0.00', 'SUDAH DIBAYAR ASURANSI', '0.0000');
        $this->assertTreatment($resolver, ClaimStatus::APPROVED_CODE, '1000.00', '1000.00', 'DICICIL ASURANSI / KURANG BAYAR', '0.0000');
        $this->assertTreatment($resolver, ClaimStatus::REJECTED_CODE, '1000.00', '0.00', 'SUDAH DIBAYAR AHLI WARIS', '0.0000');
        $this->assertTreatment($resolver, ClaimStatus::REJECTED_CODE, '1000.00', '500.00', 'DICICIL AHLI WARIS', '0.5000');
        $this->assertTreatment($resolver, ClaimStatus::REJECTED_CODE, '1000.00', '1000.00', 'BELUM DIBAYAR AHLI WARIS', '100.0000');
        $this->assertTreatment($resolver, ClaimStatus::ON_PROCESS_CODE, '1000.00', '1000.00', 'ON PROSES', '0.0000');
        $this->assertTreatment($resolver, ClaimStatus::ON_PROCESS_CODE, '1000.00', '0.00', 'ON PROSES', '0.0000');
    }

    public function test_rejected_remaining_greater_than_receivable_fails_loudly(): void
    {
        $this->expectException(ValidationException::class);

        app(ResolveClaimStatusCkpnTreatment::class)->handle(ClaimStatus::REJECTED_CODE, '1000.00', '1000.01');
    }

    public function test_unknown_claim_status_code_fails_loudly(): void
    {
        $this->expectException(ValidationException::class);

        app(ResolveClaimStatusCkpnTreatment::class)->handle('unknown_status', '1000.00', '1000.00');
    }

    private function assertTreatment(
        ResolveClaimStatusCkpnTreatment $resolver,
        string $code,
        string $receivable,
        string $remaining,
        string $keterangan,
        string $factor,
    ): void {
        $treatment = $resolver->handle($code, $receivable, $remaining);

        $this->assertSame(ClaimStatus::LABELS[$code], $treatment->claimStatusName);
        $this->assertSame($keterangan, $treatment->keterangan);
        $this->assertSame($factor, $treatment->factor);
    }
}
