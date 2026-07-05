<?php

namespace App\Actions\Ckpn;

use App\Data\CkpnCalculationInput;
use App\Data\CkpnCalculationResult;
use App\Data\CkpnReceivableCandidate;
use App\Models\CkpnWorkpaper;
use App\Services\Ckpn\CkpnCalculationService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateMonthlyCkpnWorkpaperAction
{
    public function __construct(
        private readonly CkpnCalculationService $calculationService,
        private readonly CollectCurrentReceivableCandidatesAction $collectCurrentReceivableCandidatesAction,
    ) {}

    public function handle(CkpnWorkpaper $workpaper): CkpnWorkpaper
    {
        return DB::transaction(function () use ($workpaper): CkpnWorkpaper {
            $workpaper = CkpnWorkpaper::query()
                ->whereKey($workpaper->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $workpaper->safeForGeneration()) {
                throw ValidationException::withMessages([
                    'status' => 'Only safe CKPN workpaper generation states can be generated.',
                ]);
            }

            if (
                $workpaper->items()->whereHas('adjustments')->exists()
                || $workpaper->journals()->exists()
                || $workpaper->generatedExports()->exists()
                || $workpaper->glToGlTransactions()->exists()
            ) {
                throw ValidationException::withMessages([
                    'status' => 'CKPN workpaper cannot be regenerated after adjustments or outputs exist.',
                ]);
            }

            $workpaper->items()->delete();

            $totalReceivable = BigDecimal::of('0');
            $totalCalculatedCkpn = BigDecimal::of('0');
            $periodEnd = $workpaper->periodEnd();

            $candidates = $this->collectCurrentReceivableCandidatesAction->handle($workpaper)
                ->sortBy(fn (CkpnReceivableCandidate $candidate): string => "{$candidate->originType}:{$candidate->receivableId}")
                ->values();

            foreach ($candidates as $candidate) {
                $result = $this->calculationService->calculate(new CkpnCalculationInput(
                    candidate: $candidate,
                    asOfDate: $periodEnd,
                ));

                $workpaper->items()->create([
                    'insurance_receivable_id' => $candidate->receivableId,
                    'origin_type' => $candidate->originType,
                    'branch_code' => $candidate->branchCode,
                    'branch_name' => $candidate->branchName,
                    'cif_no' => $candidate->cif,
                    'loan_account_number' => $candidate->loanAccountNumber,
                    'customer_name' => $candidate->customerName,
                    'insurance_company_name' => $candidate->insuranceCompanyName,
                    'claim_status_name' => $candidate->claimStatusName,
                    'receivable_formation_date' => $candidate->receivableFormationDate,
                    'receivable_amount' => $candidate->receivableAmount,
                    'age_days' => $result->ageDays,
                    'age_bucket_name' => $result->ageBucketName,
                    'insurance_company_weight' => $result->insuranceCompanyWeight,
                    'age_weight' => $result->ageWeight,
                    'claim_status_weight' => $result->claimStatusWeight,
                    'calculated_ckpn_rate' => $result->finalCkpnRate,
                    'calculated_ckpn_amount' => $result->ckpnAmount,
                    'adjusted_ckpn_rate' => null,
                    'adjusted_ckpn_amount' => null,
                    'adjustment_applied_at' => null,
                    'adjustment_applied_by' => null,
                    'adjustment_reason' => null,
                    'effective_ckpn_rate' => $result->finalCkpnRate,
                    'effective_ckpn_amount' => $result->ckpnAmount,
                    'calculation_rule_code' => $result->appliedRuleCode,
                    'calculation_explanation' => $result->calculationExplanation,
                    'snapshot' => $this->snapshot($candidate, $result),
                ]);

                $totalReceivable = $totalReceivable->plus($candidate->receivableAmount);
                $totalCalculatedCkpn = $totalCalculatedCkpn->plus($result->ckpnAmount);
            }

            $totalCalculatedCkpn = (string) $totalCalculatedCkpn->toScale(2, RoundingMode::HalfUp);

            $workpaper->forceFill([
                'status' => CkpnWorkpaper::STATUS_GENERATED,
                'total_receivable_amount' => (string) $totalReceivable->toScale(2, RoundingMode::HalfUp),
                'total_calculated_ckpn_amount' => $totalCalculatedCkpn,
                'total_adjustment_delta' => '0.00',
                'total_effective_ckpn_amount' => $totalCalculatedCkpn,
                'total_ckpn_amount' => $totalCalculatedCkpn,
                'last_error_message' => null,
                'generated_at' => now(),
            ])->save();

            return $workpaper->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(CkpnReceivableCandidate $candidate, CkpnCalculationResult $result): array
    {
        return [
            'source' => $candidate->sourceLabel(),
            'origin_type' => $candidate->originType,
            'insurance_receivable_id' => $candidate->receivableId,
            'branch_office_id' => $candidate->branchOfficeId,
            'branch_code' => $candidate->branchCode,
            'branch_name' => $candidate->branchName,
            'cif_no' => $candidate->cif,
            'loan_account_number' => $candidate->loanAccountNumber,
            'loan_alt_account_number' => $candidate->alternateLoanAccountNumber,
            'alt_number' => $candidate->alternateLoanAccountNumber,
            'customer_name' => $candidate->customerName,
            'date_of_death' => $candidate->dateOfDeath,
            'credit_limit' => $candidate->creditLimit,
            'loan_outstanding' => $candidate->loanOutstanding,
            'start_period' => $candidate->startPeriod,
            'end_period' => $candidate->endPeriod,
            'insurance_company' => [
                'id' => $candidate->insuranceCompanyId,
                'name' => $candidate->insuranceCompanyName,
                'ckpn_weight' => $result->insuranceCompanyWeight,
            ],
            'claim_status' => [
                'id' => $candidate->claimStatusId,
                'code' => $candidate->claimStatusCode,
                'name' => $candidate->claimStatusName,
                'ckpn_weight' => $result->claimStatusWeight,
            ],
            'receivable_formation_date' => $candidate->receivableFormationDate,
            'receivable_amount' => $candidate->receivableAmount,
            'age_days' => $result->ageDays,
            'age_bucket' => [
                'id' => $result->ageBucketId,
                'name' => $result->ageBucketName,
                'ckpn_weight' => $result->ageWeight,
            ],
            'calculated_ckpn_rate' => $result->finalCkpnRate,
            'calculated_ckpn_amount' => $result->ckpnAmount,
            'effective_ckpn_rate' => $result->finalCkpnRate,
            'effective_ckpn_amount' => $result->ckpnAmount,
            'calculation_rule_code' => $result->appliedRuleCode,
        ];
    }
}
