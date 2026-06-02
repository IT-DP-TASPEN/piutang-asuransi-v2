<?php

namespace App\Policies;

use App\Models\CkpnWorkpaper;
use App\Models\User;
use App\Support\Access\RoleScope;

class CkpnWorkpaperPolicy
{
    private const SUBJECT = 'CkpnWorkpaper';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'ViewAny');
    }

    public function view(User $user, CkpnWorkpaper $ckpnWorkpaper): bool
    {
        return $this->can($user, 'View') && $this->canAccessRecord($user, $ckpnWorkpaper);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'Create');
    }

    public function update(User $user, CkpnWorkpaper $ckpnWorkpaper): bool
    {
        return $this->can($user, 'Update')
            && $this->canAccessRecord($user, $ckpnWorkpaper)
            && in_array($ckpnWorkpaper->status, [
                CkpnWorkpaper::STATUS_DRAFT,
                CkpnWorkpaper::STATUS_RETURNED,
            ], true);
    }

    public function delete(User $user, CkpnWorkpaper $ckpnWorkpaper): bool
    {
        return $this->can($user, 'Delete') && $this->canAccessRecord($user, $ckpnWorkpaper);
    }

    public function deleteAny(User $user): bool
    {
        return $this->can($user, 'DeleteAny');
    }

    public function restore(User $user, CkpnWorkpaper $ckpnWorkpaper): bool
    {
        return $this->can($user, 'Restore') && $this->canAccessRecord($user, $ckpnWorkpaper);
    }

    public function restoreAny(User $user): bool
    {
        return $this->can($user, 'RestoreAny');
    }

    public function forceDelete(User $user, CkpnWorkpaper $ckpnWorkpaper): bool
    {
        return $this->can($user, 'ForceDelete') && $this->canAccessRecord($user, $ckpnWorkpaper);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->can($user, 'ForceDeleteAny');
    }

    public function replicate(User $user, CkpnWorkpaper $ckpnWorkpaper): bool
    {
        return $this->can($user, 'Replicate') && $this->canAccessRecord($user, $ckpnWorkpaper);
    }

    public function reorder(User $user): bool
    {
        return $this->can($user, 'Reorder');
    }

    public function generate(User $user, CkpnWorkpaper $ckpnWorkpaper): bool
    {
        return $this->can($user, 'Generate')
            && $this->canAccessRecord($user, $ckpnWorkpaper)
            && $ckpnWorkpaper->status === CkpnWorkpaper::STATUS_GENERATION_FAILED;
    }

    public function recalculate(User $user, CkpnWorkpaper $ckpnWorkpaper): bool
    {
        return $this->can($user, 'Recalculate')
            && $this->canAccessRecord($user, $ckpnWorkpaper)
            && in_array($ckpnWorkpaper->status, [
                CkpnWorkpaper::STATUS_DRAFT,
                CkpnWorkpaper::STATUS_GENERATED,
                CkpnWorkpaper::STATUS_RETURNED,
            ], true);
    }

    public function submit(User $user, CkpnWorkpaper $ckpnWorkpaper): bool
    {
        return $this->can($user, 'Submit')
            && $this->canAccessRecord($user, $ckpnWorkpaper)
            && $ckpnWorkpaper->status === CkpnWorkpaper::STATUS_GENERATED;
    }

    public function approve(User $user, CkpnWorkpaper $ckpnWorkpaper): bool
    {
        return $this->can($user, 'Approve')
            && $this->canAccessRecord($user, $ckpnWorkpaper)
            && $ckpnWorkpaper->status === CkpnWorkpaper::STATUS_SUBMITTED;
    }

    public function reject(User $user, CkpnWorkpaper $ckpnWorkpaper): bool
    {
        return $this->can($user, 'Reject')
            && $this->canAccessRecord($user, $ckpnWorkpaper)
            && $ckpnWorkpaper->status === CkpnWorkpaper::STATUS_SUBMITTED;
    }

    public function returnRequest(User $user, CkpnWorkpaper $ckpnWorkpaper): bool
    {
        return $this->can($user, 'Return')
            && $this->canAccessRecord($user, $ckpnWorkpaper)
            && $ckpnWorkpaper->status === CkpnWorkpaper::STATUS_SUBMITTED;
    }

    public function createJournal(User $user, CkpnWorkpaper $ckpnWorkpaper): bool
    {
        return $this->can($user, 'CreateJournal')
            && $this->canAccessRecord($user, $ckpnWorkpaper)
            && $ckpnWorkpaper->status === CkpnWorkpaper::STATUS_APPROVED;
    }

    public function generateExport(User $user, CkpnWorkpaper $ckpnWorkpaper): bool
    {
        return $this->can($user, 'GenerateExport')
            && $this->canAccessRecord($user, $ckpnWorkpaper)
            && $ckpnWorkpaper->status === CkpnWorkpaper::STATUS_APPROVED;
    }

    private function can(User $user, string $action): bool
    {
        return $user->can("{$action}:".self::SUBJECT);
    }

    private function canAccessRecord(User $user, CkpnWorkpaper $ckpnWorkpaper): bool
    {
        if (RoleScope::canViewAllBranches($user)) {
            return true;
        }

        if (! RoleScope::isBranchScoped($user)) {
            return false;
        }

        return $ckpnWorkpaper->branch_office_id !== null
            && $user->branch_office_id === $ckpnWorkpaper->branch_office_id;
    }
}
