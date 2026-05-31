<?php

namespace App\Policies;

use App\Models\CkpnWorkpaperItem;
use App\Models\User;
use App\Support\Access\RoleScope;

class CkpnWorkpaperItemPolicy
{
    private const SUBJECT = 'CkpnWorkpaperItem';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'ViewAny');
    }

    public function view(User $user, CkpnWorkpaperItem $ckpnWorkpaperItem): bool
    {
        return $this->can($user, 'View') && $this->canAccessRecord($user, $ckpnWorkpaperItem);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CkpnWorkpaperItem $ckpnWorkpaperItem): bool
    {
        return false;
    }

    public function delete(User $user, CkpnWorkpaperItem $ckpnWorkpaperItem): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, CkpnWorkpaperItem $ckpnWorkpaperItem): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, CkpnWorkpaperItem $ckpnWorkpaperItem): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, CkpnWorkpaperItem $ckpnWorkpaperItem): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }

    private function can(User $user, string $action): bool
    {
        return $user->can("{$action}:".self::SUBJECT);
    }

    private function canAccessRecord(User $user, CkpnWorkpaperItem $ckpnWorkpaperItem): bool
    {
        if (RoleScope::canViewAllBranches($user)) {
            return true;
        }

        if (! RoleScope::isBranchScoped($user)) {
            return false;
        }

        return $ckpnWorkpaperItem->ckpnWorkpaper->branch_office_id !== null
            && $user->branch_office_id === $ckpnWorkpaperItem->ckpnWorkpaper->branch_office_id;
    }
}
