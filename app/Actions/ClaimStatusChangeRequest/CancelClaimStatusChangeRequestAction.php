<?php

namespace App\Actions\ClaimStatusChangeRequest;

use App\Models\ClaimStatusChangeRequest;
use App\Models\User;
use App\Services\InsuranceReceivable\InsuranceReceivableStageLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelClaimStatusChangeRequestAction
{
    public function __construct(
        private readonly InsuranceReceivableStageLogger $stageLogger,
    ) {}

    public function handle(ClaimStatusChangeRequest $request, User $user, ?string $notes = null): ClaimStatusChangeRequest
    {
        if (! in_array($request->status, [
            ClaimStatusChangeRequest::STATUS_DRAFT,
            ClaimStatusChangeRequest::STATUS_RETURNED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only draft or returned claim status requests can be cancelled.',
            ]);
        }

        return DB::transaction(function () use ($request, $user, $notes): ClaimStatusChangeRequest {
            $fromStatus = $request->status;

            $request->forceFill([
                'status' => ClaimStatusChangeRequest::STATUS_CANCELLED,
            ])->save();

            $this->stageLogger->log(
                receivable: $request->insuranceReceivable,
                event: 'claim_status_update_cancelled',
                fromStatus: $fromStatus,
                toStatus: ClaimStatusChangeRequest::STATUS_CANCELLED,
                description: $notes ?: 'Claim status update request cancelled.',
                actor: $user,
            );

            return $request->refresh();
        });
    }
}
