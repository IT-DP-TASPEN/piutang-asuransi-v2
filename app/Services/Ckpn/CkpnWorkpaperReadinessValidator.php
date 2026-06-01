<?php

namespace App\Services\Ckpn;

use App\Models\CkpnWorkpaper;
use App\Models\InsuranceReceivable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class CkpnWorkpaperReadinessValidator
{
    /**
     * @return list<string>
     */
    public static function pendingWorkflowStatuses(): array
    {
        return [
            InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
            InsuranceReceivable::WORKFLOW_STATUS_SUBMITTED,
            InsuranceReceivable::WORKFLOW_STATUS_BRANCH_APPROVED,
            InsuranceReceivable::WORKFLOW_STATUS_COLLECTABILITY_CONFIRMATION_PENDING,
            InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION_PENDING,
            InsuranceReceivable::WORKFLOW_STATUS_RETURNED,
            InsuranceReceivable::WORKFLOW_STATUS_ACCOUNTING_VALIDATION,
        ];
    }

    /**
     * @return list<string>
     */
    public static function pendingSystemStatuses(): array
    {
        return [
            InsuranceReceivable::SYSTEM_STATUS_INQUIRY_QUEUED,
            InsuranceReceivable::SYSTEM_STATUS_INQUIRY_PROCESSING,
            InsuranceReceivable::SYSTEM_STATUS_INQUIRY_FAILED,
            InsuranceReceivable::SYSTEM_STATUS_BRANCH_VALIDATION_FAILED,
            InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_QUEUED,
            InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_PROCESSING,
            InsuranceReceivable::SYSTEM_STATUS_EARLY_TERMINATION_FAILED,
        ];
    }

    public function assertNoDuplicateWorkpaper(Carbon|string $period, ?int $branchOfficeId): void
    {
        $normalizedPeriod = CkpnWorkpaper::normalizePeriod($period)->toDateString();
        $branchScopeKey = CkpnWorkpaper::branchScopeKeyFor($branchOfficeId);

        $exists = CkpnWorkpaper::query()
            ->whereDate('period', $normalizedPeriod)
            ->where('branch_scope_key', $branchScopeKey)
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'period' => 'CKPN Workpaper already exists for this period and branch scope.',
            ]);
        }
    }

    public function assertNoPendingInsuranceReceivables(Carbon|string $period, ?int $branchOfficeId): void
    {
        $periodEnd = CkpnWorkpaper::normalizePeriod($period)->endOfMonth()->endOfDay();
        $count = $this->pendingInsuranceReceivablesQuery($periodEnd, $branchOfficeId)->count();

        if ($count > 0) {
            throw ValidationException::withMessages([
                'insurance_receivables' => "Cannot create CKPN Workpaper because {$count} Insurance Receivables are still pending.",
            ]);
        }
    }

    public function pendingInsuranceReceivablesCount(Carbon|string $period, ?int $branchOfficeId): int
    {
        $periodEnd = CkpnWorkpaper::normalizePeriod($period)->endOfMonth()->endOfDay();

        return $this->pendingInsuranceReceivablesQuery($periodEnd, $branchOfficeId)->count();
    }

    private function pendingInsuranceReceivablesQuery(Carbon $periodEnd, ?int $branchOfficeId): Builder
    {
        return InsuranceReceivable::query()
            ->when($branchOfficeId !== null, fn (Builder $query) => $query->where('branch_office_id', $branchOfficeId))
            ->where('workflow_status', '!=', InsuranceReceivable::WORKFLOW_STATUS_REJECTED)
            ->whereRaw('COALESCE(receivable_formation_date, date_of_death, created_at) <= ?', [$periodEnd->toDateTimeString()])
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('workflow_status', self::pendingWorkflowStatuses())
                    ->orWhereIn('system_status', self::pendingSystemStatuses());
            });
    }
}
