<?php

namespace App\Services\Approval;

use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalService
{
    /**
     * @var array<string, list<array{role_name: string|null}>>
     */
    private const WORKFLOW_STEPS = [
        ApprovalRequest::WORKFLOW_CLAIM_SUBMISSION_BRANCH => [
            ['role_name' => 'branch_approver'],
        ],
        ApprovalRequest::WORKFLOW_ACCOUNTING_RECEIVABLE_VALIDATION => [
            ['role_name' => 'accounting_approver'],
        ],
        ApprovalRequest::WORKFLOW_CLAIM_STATUS_UPDATE => [
            ['role_name' => 'business_approver'],
        ],
        ApprovalRequest::WORKFLOW_MONTHLY_CKPN_WORKPAPER => [
            ['role_name' => 'accounting_approver'],
        ],
        ApprovalRequest::WORKFLOW_CKPN_ADJUSTMENT => [
            ['role_name' => 'accounting_approver'],
        ],
    ];

    public function submit(Model $approvable, string $workflowCode, User $actor, ?string $notes = null): ApprovalRequest
    {
        $steps = $this->workflowSteps($workflowCode);

        return DB::transaction(function () use ($approvable, $workflowCode, $actor, $notes, $steps): ApprovalRequest {
            $activeRequest = $this->latestActiveRequest($approvable, $workflowCode);

            if ($activeRequest instanceof ApprovalRequest) {
                throw ValidationException::withMessages([
                    'approval' => 'Active approval request already exists.',
                ]);
            }

            /** @var ApprovalRequest $request */
            $request = $approvable->approvalRequests()->create([
                'workflow_code' => $workflowCode,
                'status' => ApprovalRequest::STATUS_SUBMITTED,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
            ]);

            foreach ($steps as $index => $step) {
                $request->steps()->create([
                    'step_order' => $index + 1,
                    'role_name' => $step['role_name'],
                    'status' => ApprovalStep::STATUS_PENDING,
                ]);
            }

            $this->writeLog($request, $actor, 'submitted', $notes);

            return $request->refresh();
        });
    }

    public function approveCurrentStep(ApprovalRequest $request, User $actor, ?string $notes = null): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $actor, $notes): ApprovalRequest {
            $request = ApprovalRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $step = $this->currentPendingStep($request);
            $this->assertCanActOnStep($step, $actor);

            $step->forceFill([
                'status' => ApprovalStep::STATUS_APPROVED,
                'acted_by' => $actor->id,
                'acted_at' => now(),
                'notes' => $notes,
            ])->save();

            $this->writeLog($request, $actor, 'approved_step', $notes, [
                'step_id' => $step->id,
                'step_order' => $step->step_order,
            ]);

            if (! $request->steps()->where('status', ApprovalStep::STATUS_PENDING)->exists()) {
                $request->forceFill([
                    'status' => ApprovalRequest::STATUS_APPROVED,
                    'final_approved_at' => now(),
                ])->save();

                $this->writeLog($request, $actor, 'approved', $notes);
            }

            return $request->refresh();
        });
    }

    public function rejectCurrentStep(ApprovalRequest $request, User $actor, ?string $notes = null): ApprovalRequest
    {
        return $this->completeCurrentStep(
            request: $request,
            actor: $actor,
            stepStatus: ApprovalStep::STATUS_REJECTED,
            requestStatus: ApprovalRequest::STATUS_REJECTED,
            action: 'rejected',
            notes: $notes,
        );
    }

    public function returnCurrentStep(ApprovalRequest $request, User $actor, ?string $notes = null): ApprovalRequest
    {
        return $this->completeCurrentStep(
            request: $request,
            actor: $actor,
            stepStatus: ApprovalStep::STATUS_RETURNED,
            requestStatus: ApprovalRequest::STATUS_RETURNED,
            action: 'returned',
            notes: $notes,
        );
    }

    public function latestActiveRequest(Model $approvable, ?string $workflowCode = null): ?ApprovalRequest
    {
        return $approvable->approvalRequests()
            ->when($workflowCode, fn ($query) => $query->where('workflow_code', $workflowCode))
            ->where('status', ApprovalRequest::STATUS_SUBMITTED)
            ->latest('id')
            ->first();
    }

    public function currentPendingStep(ApprovalRequest $request): ApprovalStep
    {
        if ($request->status !== ApprovalRequest::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'approval' => 'Approval request is not submitted.',
            ]);
        }

        $step = $request->steps()
            ->where('status', ApprovalStep::STATUS_PENDING)
            ->orderBy('step_order')
            ->first();

        if (! $step instanceof ApprovalStep) {
            throw ValidationException::withMessages([
                'approval' => 'No pending approval step found.',
            ]);
        }

        return $step;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function writeLog(ApprovalRequest $request, User $actor, string $action, ?string $notes = null, array $metadata = []): void
    {
        $request->logs()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'notes' => $notes,
            'metadata' => $metadata ?: null,
        ]);
    }

    private function completeCurrentStep(
        ApprovalRequest $request,
        User $actor,
        string $stepStatus,
        string $requestStatus,
        string $action,
        ?string $notes = null,
    ): ApprovalRequest {
        return DB::transaction(function () use ($request, $actor, $stepStatus, $requestStatus, $action, $notes): ApprovalRequest {
            $request = ApprovalRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $step = $this->currentPendingStep($request);
            $this->assertCanActOnStep($step, $actor);

            $step->forceFill([
                'status' => $stepStatus,
                'acted_by' => $actor->id,
                'acted_at' => now(),
                'notes' => $notes,
            ])->save();

            $request->forceFill([
                'status' => $requestStatus,
            ])->save();

            $this->writeLog($request, $actor, $action, $notes, [
                'step_id' => $step->id,
                'step_order' => $step->step_order,
            ]);

            return $request->refresh();
        });
    }

    /**
     * @return list<array{role_name: string|null}>
     */
    private function workflowSteps(string $workflowCode): array
    {
        $steps = self::WORKFLOW_STEPS[$workflowCode] ?? null;

        if ($steps === null) {
            throw ValidationException::withMessages([
                'workflow_code' => "Workflow {$workflowCode} is not configured.",
            ]);
        }

        return $steps;
    }

    private function assertCanActOnStep(ApprovalStep $step, User $actor): void
    {
        if ($actor->hasRole('super_admin')) {
            return;
        }

        if ($step->assigned_user_id !== null && $step->assigned_user_id !== $actor->id) {
            throw ValidationException::withMessages([
                'approval' => 'Approval step is assigned to another user.',
            ]);
        }

        if ($step->role_name !== null && ! $actor->hasRole($step->role_name)) {
            throw ValidationException::withMessages([
                'approval' => "Approval step requires role {$step->role_name}.",
            ]);
        }
    }
}
