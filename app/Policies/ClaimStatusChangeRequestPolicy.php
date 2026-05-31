<?php

namespace App\Policies;

use App\Models\ClaimStatusChangeRequest;
use App\Models\User;
use App\Support\Access\RoleScope;

class ClaimStatusChangeRequestPolicy
{
    private const SUBJECT = 'ClaimStatusChangeRequest';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'ViewAny');
    }

    public function view(User $user, ClaimStatusChangeRequest $claimStatusChangeRequest): bool
    {
        return $this->can($user, 'View') && $this->canAccessRecord($user, $claimStatusChangeRequest);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'Create');
    }

    public function update(User $user, ClaimStatusChangeRequest $claimStatusChangeRequest): bool
    {
        return $this->can($user, 'Update')
            && $this->canAccessRecord($user, $claimStatusChangeRequest)
            && ($user->hasRole('super_admin') || in_array($claimStatusChangeRequest->status, [
                ClaimStatusChangeRequest::STATUS_DRAFT,
                ClaimStatusChangeRequest::STATUS_RETURNED,
            ], true));
    }

    public function delete(User $user, ClaimStatusChangeRequest $claimStatusChangeRequest): bool
    {
        return $this->can($user, 'Delete') && $this->canAccessRecord($user, $claimStatusChangeRequest);
    }

    public function deleteAny(User $user): bool
    {
        return $this->can($user, 'DeleteAny');
    }

    public function restore(User $user, ClaimStatusChangeRequest $claimStatusChangeRequest): bool
    {
        return $this->can($user, 'Restore') && $this->canAccessRecord($user, $claimStatusChangeRequest);
    }

    public function restoreAny(User $user): bool
    {
        return $this->can($user, 'RestoreAny');
    }

    public function forceDelete(User $user, ClaimStatusChangeRequest $claimStatusChangeRequest): bool
    {
        return $this->can($user, 'ForceDelete') && $this->canAccessRecord($user, $claimStatusChangeRequest);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->can($user, 'ForceDeleteAny');
    }

    public function replicate(User $user, ClaimStatusChangeRequest $claimStatusChangeRequest): bool
    {
        return $this->can($user, 'Replicate') && $this->canAccessRecord($user, $claimStatusChangeRequest);
    }

    public function reorder(User $user): bool
    {
        return $this->can($user, 'Reorder');
    }

    public function submit(User $user, ClaimStatusChangeRequest $claimStatusChangeRequest): bool
    {
        return $this->can($user, 'Submit') && $this->canAccessRecord($user, $claimStatusChangeRequest);
    }

    public function approve(User $user, ClaimStatusChangeRequest $claimStatusChangeRequest): bool
    {
        return $this->can($user, 'Approve') && $this->canAccessRecord($user, $claimStatusChangeRequest);
    }

    public function reject(User $user, ClaimStatusChangeRequest $claimStatusChangeRequest): bool
    {
        return $this->can($user, 'Reject') && $this->canAccessRecord($user, $claimStatusChangeRequest);
    }

    public function returnRequest(User $user, ClaimStatusChangeRequest $claimStatusChangeRequest): bool
    {
        return $this->can($user, 'Return') && $this->canAccessRecord($user, $claimStatusChangeRequest);
    }

    private function can(User $user, string $action): bool
    {
        return $user->can("{$action}:".self::SUBJECT);
    }

    private function canAccessRecord(User $user, ClaimStatusChangeRequest $claimStatusChangeRequest): bool
    {
        if (RoleScope::canViewAllBranches($user)) {
            return true;
        }

        if (! RoleScope::isBranchScoped($user)) {
            return false;
        }

        return $user->branch_office_id === $claimStatusChangeRequest->insuranceReceivable->branch_office_id;
    }
}
