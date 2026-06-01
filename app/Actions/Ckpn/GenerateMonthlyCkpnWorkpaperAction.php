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
        private readonly CollectLegacyReceivableCandidatesAction $collectLegacyReceivableCandidatesAction,
    ) {}

    public function handle(CkpnWorkpaper $workpaper): CkpnWorkpaper
    {
        if (! in_array($workpaper->status ?? CkpnWorkpaper::STATUS_DRAFT, [
            CkpnWorkpaper::STATUS_DRAFT,
            CkpnWorkpaper::STATUS_GENERATED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only draft or generated CKPN workpapers can be generated.',
            ]);
        }

        return DB::transaction(function () use ($workpaper): CkpnWorkpaper {
            $workpaper->items()->delete();

            $totalReceivable = BigDecimal::of('0');
            $totalCkpn = BigDecimal::of('0');

            $candidates = $this->collectCurrentReceivableCandidatesAction->handle($workpaper)
                ->concat($this->collectLegacyReceivableCandidatesAction->handle($workpaper))
                ->sortBy(fn (CkpnReceivableCandidate $candidate): string => "{$candidate->receivableType}:{$candidate->receivableId}")
                ->values();

            foreach ($candidates as $candidate) {
                $result = $this->calculationService->calculate(new CkpnCalculationInput(
                    candidate: $candidate,
                    asOfDate: $workpaper->period,
                ));

                $workpaper->items()->create([
                    'receivable_type' => $candidate->receivableType,
                    'receivable_id' => $candidate->receivableId,
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
                    'final_ckpn_rate' => $result->finalCkpnRate,
                    'ckpn_amount' => $result->ckpnAmount,
                    'calculation_rule_code' => $result->appliedRuleCode,
                    'calculation_explanation' => $result->calculationExplanation,
                    'snapshot' => $this->snapshot($candidate, $result),
                ]);

                $totalReceivable = $totalReceivable->plus($candidate->receivableAmount);
                $totalCkpn = $totalCkpn->plus($result->ckpnAmount);
            }

            $workpaper->forceFill([
                'status' => CkpnWorkpaper::STATUS_GENERATED,
                'total_receivable_amount' => (string) $totalReceivable->toScale(2, RoundingMode::HALF_UP),
                'total_ckpn_amount' => (string) $totalCkpn->toScale(2, RoundingMode::HALF_UP),
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
            'receivable_type' => $candidate->receivableType,
            'receivable_id' => $candidate->receivableId,
            'branch_office_id' => $candidate->branchOfficeId,
            'branch_code' => $candidate->branchCode,
            'branch_name' => $candidate->branchName,
            'cif_no' => $candidate->cif,
            'loan_account_number' => $candidate->loanAccountNumber,
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
            'final_ckpn_rate' => $result->finalCkpnRate,
            'ckpn_amount' => $result->ckpnAmount,
            'calculation_rule_code' => $result->appliedRuleCode,
        ];
    }
}
