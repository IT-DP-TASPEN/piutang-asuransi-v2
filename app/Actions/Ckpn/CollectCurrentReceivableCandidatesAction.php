<?php

namespace App\Actions\Ckpn;

use App\Data\CkpnReceivableCandidate;
use App\Models\CkpnWorkpaper;
use App\Models\InsuranceReceivable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CollectCurrentReceivableCandidatesAction
{
    /**
     * @return Collection<int, CkpnReceivableCandidate>
     */
    public function handle(CkpnWorkpaper $workpaper): Collection
    {
        $periodEnd = $workpaper->periodEnd()->toDateString();

        return InsuranceReceivable::query()
            ->with(['branchOffice', 'insuranceCompany', 'claimStatus'])
            ->whereNotNull('receivable_formation_date')
            ->whereNotNull('receivable_amount')
            ->whereDate('receivable_formation_date', '<=', $periodEnd)
            ->where('remaining_receivable_amount', '>', 0)
            ->whereNotIn('workflow_status', [
                InsuranceReceivable::WORKFLOW_STATUS_REJECTED,
                InsuranceReceivable::WORKFLOW_STATUS_CANCELLED,
            ])
            ->when($workpaper->branch_office_id !== null, fn (Builder $query) => $query->where('branch_office_id', $workpaper->branch_office_id))
            ->orderBy('id')
            ->get()
            ->map(fn (InsuranceReceivable $receivable): CkpnReceivableCandidate => new CkpnReceivableCandidate(
                receivableId: $receivable->id,
                originType: $receivable->origin_type ?? InsuranceReceivable::ORIGIN_TYPE_WORKFLOW,
                branchOfficeId: $receivable->branch_office_id,
                branchCode: $receivable->branch_code ?: $receivable->branchOffice->branch_code,
                branchName: $receivable->branchOffice->branch_name,
                cif: $receivable->cif_no,
                loanAccountNumber: $receivable->loan_account_number,
                customerName: $receivable->customer_name,
                insuranceCompanyId: $receivable->insurance_company_id,
                insuranceCompanyName: $receivable->insuranceCompany->name,
                insuranceCompanyWeight: $receivable->insuranceCompany->ckpn_weight,
                claimStatusId: $receivable->claim_status_id,
                claimStatusCode: $receivable->claimStatus->code,
                claimStatusName: $receivable->claimStatus->name,
                receivableFormationDate: $receivable->receivable_formation_date->toDateString(),
                receivableAmount: $receivable->receivable_amount,
                remainingReceivableAmount: $receivable->remaining_receivable_amount,
                dateOfDeath: $receivable->date_of_death?->toDateString(),
                creditLimit: $receivable->credit_limit,
                loanOutstanding: $receivable->loan_outstanding,
                startPeriod: $receivable->start_period?->toDateString(),
                endPeriod: $receivable->end_period?->toDateString(),
                alternateLoanAccountNumber: $receivable->alt_number,
            ));
    }
}
