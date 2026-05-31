<?php

namespace App\Policies;

use App\Models\CkpnAdjustment;
use App\Models\User;
use App\Support\Access\RoleScope;

class CkpnAdjustmentPolicy
{
    private const SUBJECT = 'CkpnAdjustment';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'ViewAny');
    }

    public function view(User $user, CkpnAdjustment $ckpnAdjustment): bool
    {
        return $this->can($user, 'View') && $this->canAccessRecord($user, $ckpnAdjustment);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'Create');
    }

    public function update(User $user, CkpnAdjustment $ckpnAdjustment): bool
    {
        return $this->can($user, 'Update') && $this->canAccessRecord($user, $ckpnAdjustment);
    }

    public function delete(User $user, CkpnAdjustment $ckpnAdjustment): bool
    {
        return $this->can($user, 'Delete') && $this->canAccessRecord($user, $ckpnAdjustment);
    }

    public function deleteAny(User $user): bool
    {
        return $this->can($user, 'DeleteAny');
    }

    public function restore(User $user, CkpnAdjustment $ckpnAdjustment): bool
    {
        return $this->can($user, 'Restore') && $this->canAccessRecord($user, $ckpnAdjustment);
    }

    public function restoreAny(User $user): bool
    {
        return $this->can($user, 'RestoreAny');
    }

    public function forceDelete(User $user, CkpnAdjustment $ckpnAdjustment): bool
    {
        return $this->can($user, 'ForceDelete') && $this->canAccessRecord($user, $ckpnAdjustment);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->can($user, 'ForceDeleteAny');
    }

    public function replicate(User $user, CkpnAdjustment $ckpnAdjustment): bool
    {
        return $this->can($user, 'Replicate') && $this->canAccessRecord($user, $ckpnAdjustment);
    }

    public function reorder(User $user): bool
    {
        return $this->can($user, 'Reorder');
    }

    public function submit(User $user, CkpnAdjustment $ckpnAdjustment): bool
    {
        return $this->can($user, 'Submit') && $this->canAccessRecord($user, $ckpnAdjustment);
    }

    public function approve(User $user, CkpnAdjustment $ckpnAdjustment): bool
    {
        return $this->can($user, 'Approve') && $this->canAccessRecord($user, $ckpnAdjustment);
    }

    public function reject(User $user, CkpnAdjustment $ckpnAdjustment): bool
    {
        return $this->can($user, 'Reject') && $this->canAccessRecord($user, $ckpnAdjustment);
    }

    public function returnRequest(User $user, CkpnAdjustment $ckpnAdjustment): bool
    {
        return $this->can($user, 'Return') && $this->canAccessRecord($user, $ckpnAdjustment);
    }

    private function can(User $user, string $action): bool
    {
        return $user->can("{$action}:".self::SUBJECT);
    }

    private function canAccessRecord(User $user, CkpnAdjustment $ckpnAdjustment): bool
    {
        if (RoleScope::canViewAllBranches($user)) {
            return true;
        }

        if (! RoleScope::isBranchScoped($user)) {
            return false;
        }

        return $user->branch_office_id === $ckpnAdjustment->insuranceReceivable->branch_office_id;
    }
}
