<?php

namespace App\Policies;

use App\Models\GlToGlTransaction;
use App\Models\User;
use App\Support\Access\RoleScope;

class GlToGlTransactionPolicy
{
    private const SUBJECT = 'GlToGlTransaction';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'ViewAny');
    }

    public function view(User $user, GlToGlTransaction $glToGlTransaction): bool
    {
        return $this->can($user, 'View') && $this->canAccessRecord($user, $glToGlTransaction);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'Create');
    }

    public function update(User $user, GlToGlTransaction $glToGlTransaction): bool
    {
        return $this->can($user, 'Update') && $this->canAccessRecord($user, $glToGlTransaction);
    }

    public function delete(User $user, GlToGlTransaction $glToGlTransaction): bool
    {
        return $this->can($user, 'Delete') && $this->canAccessRecord($user, $glToGlTransaction);
    }

    public function deleteAny(User $user): bool
    {
        return $this->can($user, 'DeleteAny');
    }

    public function restore(User $user, GlToGlTransaction $glToGlTransaction): bool
    {
        return $this->can($user, 'Restore') && $this->canAccessRecord($user, $glToGlTransaction);
    }

    public function restoreAny(User $user): bool
    {
        return $this->can($user, 'RestoreAny');
    }

    public function forceDelete(User $user, GlToGlTransaction $glToGlTransaction): bool
    {
        return $this->can($user, 'ForceDelete') && $this->canAccessRecord($user, $glToGlTransaction);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->can($user, 'ForceDeleteAny');
    }

    public function replicate(User $user, GlToGlTransaction $glToGlTransaction): bool
    {
        return $this->can($user, 'Replicate') && $this->canAccessRecord($user, $glToGlTransaction);
    }

    public function reorder(User $user): bool
    {
        return $this->can($user, 'Reorder');
    }

    private function can(User $user, string $action): bool
    {
        return $user->can("{$action}:".self::SUBJECT);
    }

    private function canAccessRecord(User $user, GlToGlTransaction $glToGlTransaction): bool
    {
        if (RoleScope::canViewAllBranches($user)) {
            return true;
        }

        if (! RoleScope::isBranchScoped($user)) {
            return false;
        }

        $branchOfficeId = $glToGlTransaction->ckpnJournal?->branch_office_id
            ?? $glToGlTransaction->ckpnWorkpaper?->branch_office_id;

        return $branchOfficeId !== null && $user->branch_office_id === $branchOfficeId;
    }
}
