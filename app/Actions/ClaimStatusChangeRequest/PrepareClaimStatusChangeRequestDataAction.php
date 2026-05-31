<?php

namespace App\Actions\ClaimStatusChangeRequest;

use App\Models\ClaimStatusChangeRequest;
use App\Models\InsuranceReceivable;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class PrepareClaimStatusChangeRequestDataAction
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function handle(array $data, User $user): array
    {
        $receivable = InsuranceReceivable::query()->find($data['insurance_receivable_id'] ?? null);

        if (! $receivable instanceof InsuranceReceivable) {
            throw ValidationException::withMessages([
                'insurance_receivable_id' => 'Insurance receivable is required.',
            ]);
        }

        return [
            ...$data,
            'from_claim_status_id' => $receivable->claim_status_id,
            'requested_by' => $data['requested_by'] ?? $user->id,
            'status' => $data['status'] ?? ClaimStatusChangeRequest::STATUS_DRAFT,
        ];
    }
}
