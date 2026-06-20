<?php

namespace App\Actions\Ckpn;

use App\Data\CkpnReceivableCandidate;
use App\Models\CkpnWorkpaper;
use App\Models\LegacyReceivable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CollectLegacyReceivableCandidatesAction
{
    /**
     * @return Collection<int, CkpnReceivableCandidate>
     */
    public function handle(CkpnWorkpaper $workpaper): Collection
    {
        $periodEnd = $workpaper->periodEnd()->toDateString();

        return LegacyReceivable::query()
            ->with(['branchOffice', 'insuranceCompany', 'claimStatus'])
            ->whereDate('date_of_death', '<=', $periodEnd)
            ->where(function (Builder $query) use ($periodEnd): void {
                $query->whereNull('receivable_formation_date')
                    ->orWhereDate('receivable_formation_date', '<=', $periodEnd);
            })
            ->where('remaining_receivable_amount', '>', 0)
            ->when($workpaper->branch_office_id !== null, fn (Builder $query) => $query->where('branch_office_id', $workpaper->branch_office_id))
            ->orderBy('id')
            ->get()
            ->map(function (LegacyReceivable $receivable): CkpnReceivableCandidate {
                $agingDate = $receivable->receivable_formation_date ?? $receivable->date_of_death;

                return new CkpnReceivableCandidate(
                    receivableType: LegacyReceivable::class,
                    receivableId: $receivable->id,
                    branchOfficeId: $receivable->branch_office_id,
                    branchCode: $receivable->branchOffice->branch_code,
                    branchName: $receivable->branchOffice->branch_name,
                    cif: $receivable->cif,
                    loanAccountNumber: $receivable->loan_account_number,
                    customerName: $receivable->customer_name,
                    insuranceCompanyId: $receivable->insurance_company_id,
                    insuranceCompanyName: $receivable->insuranceCompany->name,
                    insuranceCompanyWeight: $receivable->insuranceCompany->ckpn_weight,
                    claimStatusId: $receivable->claim_status_id,
                    claimStatusCode: $receivable->claimStatus->code,
                    claimStatusName: $receivable->claimStatus->name,
                    claimStatusWeight: $receivable->claimStatus->ckpn_weight,
                    receivableFormationDate: $agingDate->toDateString(),
                    receivableAmount: $receivable->remaining_receivable_amount,
                    dateOfDeath: $receivable->date_of_death->toDateString(),
                    creditLimit: null,
                    loanOutstanding: $receivable->loan_outstanding,
                    startPeriod: null,
                    endPeriod: null,
                );
            });
    }
}
