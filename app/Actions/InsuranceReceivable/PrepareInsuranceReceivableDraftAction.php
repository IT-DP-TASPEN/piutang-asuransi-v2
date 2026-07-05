<?php

namespace App\Actions\InsuranceReceivable;

use App\Models\ClaimStatus;
use App\Models\InsuranceReceivable;
use App\Models\User;

class PrepareInsuranceReceivableDraftAction
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function handle(array $data, User $user): array
    {
        $branchOffice = $user->branchOffice()->firstOrFail();
        $claimStatusId = ClaimStatus::query()
            ->where('code', ClaimStatus::DEFAULT_CODE)
            ->value('id');

        return [
            ...$data,
            'origin_type' => InsuranceReceivable::ORIGIN_TYPE_WORKFLOW,
            'branch_office_id' => $data['branch_office_id'] ?? $branchOffice->id,
            'branch_code' => $data['branch_code'] ?? $branchOffice->branch_code,
            'claim_status_id' => $data['claim_status_id'] ?? $claimStatusId,
            'workflow_status' => $data['workflow_status'] ?? InsuranceReceivable::WORKFLOW_STATUS_DRAFT,
            'created_by' => $data['created_by'] ?? $user->id,
        ];
    }
}
