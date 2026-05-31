<?php

namespace App\Actions\Ckpn;

use App\Data\CkpnCalculationInput;
use App\Data\CkpnCalculationResult;
use App\Models\CkpnWorkpaper;
use App\Models\InsuranceReceivable;
use App\Services\Ckpn\CkpnCalculationService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateMonthlyCkpnWorkpaperAction
{
    public function __construct(
        private readonly CkpnCalculationService $calculationService,
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

            $this->eligibleReceivables($workpaper)
                ->with(['insuranceCompany', 'claimStatus'])
                ->orderBy('id')
                ->chunkById(100, function ($receivables) use ($workpaper, &$totalReceivable, &$totalCkpn): void {
                    foreach ($receivables as $receivable) {
                        $result = $this->calculationService->calculate(new CkpnCalculationInput(
                            insuranceReceivable: $receivable,
                            asOfDate: $workpaper->period,
                        ));

                        $workpaper->items()->create([
                            'insurance_receivable_id' => $receivable->id,
                            'branch_code' => $receivable->branch_code,
                            'cif_no' => $receivable->cif_no,
                            'loan_account_number' => $receivable->loan_account_number,
                            'customer_name' => $receivable->customer_name,
                            'insurance_company_name' => $receivable->insuranceCompany->name,
                            'claim_status_name' => $receivable->claimStatus->name,
                            'receivable_formation_date' => $receivable->receivable_formation_date,
                            'receivable_amount' => $receivable->receivable_amount,
                            'age_days' => $result->ageDays,
                            'age_bucket_name' => $result->ageBucketName,
                            'insurance_company_weight' => $result->insuranceCompanyWeight,
                            'age_weight' => $result->ageWeight,
                            'claim_status_weight' => $result->claimStatusWeight,
                            'final_ckpn_rate' => $result->finalCkpnRate,
                            'ckpn_amount' => $result->ckpnAmount,
                            'calculation_rule_code' => $result->appliedRuleCode,
                            'calculation_explanation' => $result->calculationExplanation,
                            'snapshot' => $this->snapshot($receivable, $result),
                        ]);

                        $totalReceivable = $totalReceivable->plus($receivable->receivable_amount);
                        $totalCkpn = $totalCkpn->plus($result->ckpnAmount);
                    }
                });

            $workpaper->forceFill([
                'status' => CkpnWorkpaper::STATUS_GENERATED,
                'total_receivable_amount' => (string) $totalReceivable->toScale(2, RoundingMode::HALF_UP),
                'total_ckpn_amount' => (string) $totalCkpn->toScale(2, RoundingMode::HALF_UP),
            ])->save();

            return $workpaper->refresh();
        });
    }

    public function eligibleReceivables(CkpnWorkpaper $workpaper): Builder
    {
        return InsuranceReceivable::query()
            ->whereNotNull('receivable_formation_date')
            ->whereDate('receivable_formation_date', '<=', $workpaper->period)
            ->where('receivable_amount', '>', 0)
            ->whereNotIn('workflow_status', [
                InsuranceReceivable::WORKFLOW_STATUS_REJECTED,
                'cancelled',
            ])
            ->when($workpaper->branch_office_id !== null, fn (Builder $query) => $query->where('branch_office_id', $workpaper->branch_office_id));
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(InsuranceReceivable $receivable, CkpnCalculationResult $result): array
    {
        return [
            'insurance_receivable_id' => $receivable->id,
            'branch_code' => $receivable->branch_code,
            'cif_no' => $receivable->cif_no,
            'loan_account_number' => $receivable->loan_account_number,
            'customer_name' => $receivable->customer_name,
            'insurance_company' => [
                'id' => $receivable->insuranceCompany->id,
                'name' => $receivable->insuranceCompany->name,
                'ckpn_weight' => $result->insuranceCompanyWeight,
            ],
            'claim_status' => [
                'id' => $receivable->claimStatus->id,
                'code' => $receivable->claimStatus->code,
                'name' => $receivable->claimStatus->name,
                'ckpn_weight' => $result->claimStatusWeight,
            ],
            'receivable_formation_date' => $receivable->receivable_formation_date?->toDateString(),
            'receivable_amount' => $receivable->receivable_amount,
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
