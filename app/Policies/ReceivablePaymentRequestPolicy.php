<?php

namespace App\Policies;

use App\Models\ReceivablePaymentRequest;
use App\Models\User;
use App\Support\Access\RoleScope;

class ReceivablePaymentRequestPolicy
{
    private const SUBJECT = 'ReceivablePaymentRequest';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'ViewAny');
    }

    public function view(User $user, ReceivablePaymentRequest $request): bool
    {
        return $this->can($user, 'View') && $this->canAccessRecord($user, $request);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'Create');
    }

    public function submit(User $user): bool
    {
        return $this->can($user, 'Submit');
    }

    public function approve(User $user, ReceivablePaymentRequest $request): bool
    {
        return $this->can($user, 'Approve')
            && $this->canAccessRecord($user, $request)
            && $request->canRetry();
    }

    public function reject(User $user, ReceivablePaymentRequest $request): bool
    {
        return $this->can($user, 'Reject')
            && $this->canAccessRecord($user, $request)
            && $request->status === ReceivablePaymentRequest::STATUS_SUBMITTED;
    }

    public function retry(User $user, ReceivablePaymentRequest $request): bool
    {
        return $this->can($user, 'Retry')
            && $this->canAccessRecord($user, $request)
            && in_array($request->status, [
                ReceivablePaymentRequest::STATUS_VALIDATION_FAILED,
                ReceivablePaymentRequest::STATUS_GL_FAILED,
            ], true);
    }

    public function update(User $user, ReceivablePaymentRequest $request): bool
    {
        return false;
    }

    public function delete(User $user, ReceivablePaymentRequest $request): bool
    {
        return false;
    }

    private function can(User $user, string $action): bool
    {
        return $user->can("{$action}:".self::SUBJECT);
    }

    private function canAccessRecord(User $user, ReceivablePaymentRequest $request): bool
    {
        if (RoleScope::canViewAllBranches($user)) {
            return true;
        }

        if (! RoleScope::isBranchScoped($user)) {
            return false;
        }

        return $user->branch_office_id === $request->insuranceReceivable?->branch_office_id;
    }
}
