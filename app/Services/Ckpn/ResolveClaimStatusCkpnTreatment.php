<?php

namespace App\Services\Ckpn;

use App\Data\ClaimStatusCkpnTreatment;
use App\Models\ClaimStatus;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class ResolveClaimStatusCkpnTreatment
{
    public const KETERANGAN_ON_PROSES = 'ON PROSES';

    public const KETERANGAN_SUDAH_DIBAYAR_ASURANSI = 'SUDAH DIBAYAR ASURANSI';

    public const KETERANGAN_DICICIL_ASURANSI = 'DICICIL ASURANSI / KURANG BAYAR';

    public const KETERANGAN_SUDAH_DIBAYAR_AHLI_WARIS = 'SUDAH DIBAYAR AHLI WARIS';

    public const KETERANGAN_DICICIL_AHLI_WARIS = 'DICICIL AHLI WARIS';

    public const KETERANGAN_BELUM_DIBAYAR_AHLI_WARIS = 'BELUM DIBAYAR AHLI WARIS';

    public function handle(string $claimStatusCode, string $receivableAmount, string $remainingReceivableAmount): ClaimStatusCkpnTreatment
    {
        $receivable = $this->decimal($receivableAmount, 'receivable_amount');
        $remaining = $this->decimal($remainingReceivableAmount, 'remaining_receivable_amount');

        if ($receivable->isLessThan('0') || $remaining->isLessThan('0')) {
            throw ValidationException::withMessages([
                'receivable_amount' => 'Receivable amounts cannot be negative.',
            ]);
        }

        if ($remaining->isGreaterThan($receivable)) {
            throw ValidationException::withMessages([
                'remaining_receivable_amount' => 'Remaining receivable amount cannot exceed receivable amount.',
            ]);
        }

        return match ($claimStatusCode) {
            ClaimStatus::APPROVED_CODE => new ClaimStatusCkpnTreatment(
                ClaimStatus::LABELS[ClaimStatus::APPROVED_CODE],
                $remaining->isEqualTo('0') ? self::KETERANGAN_SUDAH_DIBAYAR_ASURANSI : self::KETERANGAN_DICICIL_ASURANSI,
                $this->factor('0'),
            ),
            ClaimStatus::REJECTED_CODE => $this->rejected($remaining, $receivable),
            ClaimStatus::ON_PROCESS_CODE => new ClaimStatusCkpnTreatment(
                ClaimStatus::LABELS[ClaimStatus::ON_PROCESS_CODE],
                self::KETERANGAN_ON_PROSES,
                $this->factor('0'),
            ),
            default => throw ValidationException::withMessages([
                'claim_status_code' => "Unknown claim status code: {$claimStatusCode}.",
            ]),
        };
    }

    private function rejected(BigDecimal $remaining, BigDecimal $receivable): ClaimStatusCkpnTreatment
    {
        if ($remaining->isEqualTo('0')) {
            return new ClaimStatusCkpnTreatment(
                ClaimStatus::LABELS[ClaimStatus::REJECTED_CODE],
                self::KETERANGAN_SUDAH_DIBAYAR_AHLI_WARIS,
                $this->factor('0'),
            );
        }

        if ($remaining->isEqualTo($receivable)) {
            return new ClaimStatusCkpnTreatment(
                ClaimStatus::LABELS[ClaimStatus::REJECTED_CODE],
                self::KETERANGAN_BELUM_DIBAYAR_AHLI_WARIS,
                $this->factor('100'),
            );
        }

        return new ClaimStatusCkpnTreatment(
            ClaimStatus::LABELS[ClaimStatus::REJECTED_CODE],
            self::KETERANGAN_DICICIL_AHLI_WARIS,
            $this->factor('0.5'),
        );
    }

    private function decimal(string $value, string $field): BigDecimal
    {
        try {
            return BigDecimal::of($value);
        } catch (MathException) {
            throw ValidationException::withMessages([
                $field => "{$field} must be numeric.",
            ]);
        }
    }

    private function factor(string $value): string
    {
        return (string) BigDecimal::of($value)->toScale(4, RoundingMode::HalfUp);
    }
}
