<?php

namespace App\Services\InsuranceReceivable;

use App\Models\ApiIntegrationLog;
use App\Models\ApprovalRequest;
use App\Models\InsuranceReceivable;
use App\Models\InsuranceReceivableStageLog;
use App\Models\User;

class InsuranceReceivableStageLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        InsuranceReceivable $receivable,
        string $event,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $description = null,
        array $metadata = [],
        ?User $actor = null,
        ?ApprovalRequest $approvalRequest = null,
        ?ApiIntegrationLog $apiLog = null,
        ?string $triggeredByType = null,
    ): InsuranceReceivableStageLog {
        return $receivable->stageLogs()->create([
            'from_stage' => null,
            'to_stage' => $receivable->stage,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'event' => $event,
            'description' => $description,
            'triggered_by_type' => $triggeredByType ?? ($actor instanceof User ? 'user' : 'system'),
            'triggered_by_id' => $actor?->id,
            'approval_request_id' => $approvalRequest?->id,
            'api_integration_log_id' => $apiLog?->id,
            'metadata' => $metadata ?: null,
        ]);
    }
}
