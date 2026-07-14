<?php

namespace App\Services\Approval\ApprovalQueue;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Approval\ApprovalQueueWorkflowAdapter;
use App\Services\Approval\ApprovalService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

abstract class BaseApprovalQueueWorkflowAdapter implements ApprovalQueueWorkflowAdapter
{
    public function __construct(
        protected readonly ApprovalService $approvalService,
    ) {}

    public function submittedByLabel(ApprovalRequest $request): ?string
    {
        return $request->submitter?->name;
    }

    public function detailRoute(ApprovalRequest $request): ?string
    {
        return null;
    }

    public function canView(ApprovalRequest $request, User $user): bool
    {
        $approvable = $request->approvable;

        return $approvable instanceof Model && $user->can('view', $approvable);
    }

    public function canApprove(ApprovalRequest $request, User $user): bool
    {
        return false;
    }

    public function canReturn(ApprovalRequest $request, User $user): bool
    {
        return false;
    }

    public function canReject(ApprovalRequest $request, User $user): bool
    {
        return false;
    }

    public function approve(ApprovalRequest $request, User $user, ?string $notes = null): void
    {
        $this->unsupported();
    }

    public function return(ApprovalRequest $request, User $user, ?string $notes = null): void
    {
        $this->unsupported();
    }

    public function reject(ApprovalRequest $request, User $user, ?string $notes = null): void
    {
        $this->unsupported();
    }

    public function notesRequired(string $action, ApprovalRequest $request): bool
    {
        return false;
    }

    public function confirmationDescription(string $action, ApprovalRequest $request): ?string
    {
        return null;
    }

    public function snapshot(ApprovalRequest $request): array
    {
        return [];
    }

    public function isCurrent(ApprovalRequest $request): bool
    {
        $approvable = $request->approvable;

        if (! $approvable instanceof Model) {
            return false;
        }

        $current = $this->approvalService->latestActiveRequest($approvable, $request->workflow_code);

        return $current instanceof ApprovalRequest && $current->is($request);
    }

    protected function submittedAndCurrent(ApprovalRequest $request): bool
    {
        return $request->status === ApprovalRequest::STATUS_SUBMITTED && $this->isCurrent($request);
    }

    protected function canActOnCurrentStep(ApprovalRequest $request, User $user): bool
    {
        return $this->approvalService->canActOnCurrentStep($request, $user);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @return TModel|null
     */
    protected function approvable(ApprovalRequest $request, string $class): ?Model
    {
        $approvable = $request->approvable;

        return $approvable instanceof $class ? $approvable : null;
    }

    protected function money(mixed $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return 'IDR '.number_format((float) $amount, 0, ',', '.');
    }

    protected function value(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    protected function unsupported(): never
    {
        throw ValidationException::withMessages([
            'approval' => 'This workflow cannot be acted on from Approval Queue.',
        ]);
    }
}
