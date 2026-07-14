<?php

namespace App\Services\Approval;

use App\Models\ApprovalRequest;
use App\Models\User;

interface ApprovalQueueWorkflowAdapter
{
    public function label(ApprovalRequest $request): string;

    public function summary(ApprovalRequest $request): string;

    public function reference(ApprovalRequest $request): ?string;

    public function branchLabel(ApprovalRequest $request): ?string;

    public function amountLabel(ApprovalRequest $request): ?string;

    public function submittedByLabel(ApprovalRequest $request): ?string;

    public function detailRoute(ApprovalRequest $request): ?string;

    public function canView(ApprovalRequest $request, User $user): bool;

    public function canApprove(ApprovalRequest $request, User $user): bool;

    public function canReturn(ApprovalRequest $request, User $user): bool;

    public function canReject(ApprovalRequest $request, User $user): bool;

    public function approve(ApprovalRequest $request, User $user, ?string $notes = null): void;

    public function return(ApprovalRequest $request, User $user, ?string $notes = null): void;

    public function reject(ApprovalRequest $request, User $user, ?string $notes = null): void;

    public function notesRequired(string $action, ApprovalRequest $request): bool;

    public function confirmationDescription(string $action, ApprovalRequest $request): ?string;

    /**
     * @return array<string, string|null>
     */
    public function snapshot(ApprovalRequest $request): array;

    public function isCurrent(ApprovalRequest $request): bool;
}
