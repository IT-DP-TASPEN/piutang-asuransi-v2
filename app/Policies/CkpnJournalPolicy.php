<?php

namespace App\Policies;

use App\Models\CkpnJournal;
use App\Models\User;
use App\Support\Access\RoleScope;

class CkpnJournalPolicy
{
    private const SUBJECT = 'CkpnJournal';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'ViewAny');
    }

    public function view(User $user, CkpnJournal $ckpnJournal): bool
    {
        return $this->can($user, 'View') && $this->canAccessRecord($user, $ckpnJournal);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'Create');
    }

    public function update(User $user, CkpnJournal $ckpnJournal): bool
    {
        return $this->can($user, 'Update') && $this->canAccessRecord($user, $ckpnJournal);
    }

    public function delete(User $user, CkpnJournal $ckpnJournal): bool
    {
        return $this->can($user, 'Delete') && $this->canAccessRecord($user, $ckpnJournal);
    }

    public function deleteAny(User $user): bool
    {
        return $this->can($user, 'DeleteAny');
    }

    public function restore(User $user, CkpnJournal $ckpnJournal): bool
    {
        return $this->can($user, 'Restore') && $this->canAccessRecord($user, $ckpnJournal);
    }

    public function restoreAny(User $user): bool
    {
        return $this->can($user, 'RestoreAny');
    }

    public function forceDelete(User $user, CkpnJournal $ckpnJournal): bool
    {
        return $this->can($user, 'ForceDelete') && $this->canAccessRecord($user, $ckpnJournal);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->can($user, 'ForceDeleteAny');
    }

    public function replicate(User $user, CkpnJournal $ckpnJournal): bool
    {
        return $this->can($user, 'Replicate') && $this->canAccessRecord($user, $ckpnJournal);
    }

    public function reorder(User $user): bool
    {
        return $this->can($user, 'Reorder');
    }

    public function submit(User $user, CkpnJournal $ckpnJournal): bool
    {
        return $this->can($user, 'Submit') && $this->canAccessRecord($user, $ckpnJournal);
    }

    public function approve(User $user, CkpnJournal $ckpnJournal): bool
    {
        return $this->can($user, 'Approve') && $this->canAccessRecord($user, $ckpnJournal);
    }

    public function reject(User $user, CkpnJournal $ckpnJournal): bool
    {
        return $this->can($user, 'Reject') && $this->canAccessRecord($user, $ckpnJournal);
    }

    public function returnRequest(User $user, CkpnJournal $ckpnJournal): bool
    {
        return $this->can($user, 'Return') && $this->canAccessRecord($user, $ckpnJournal);
    }

    public function executeGlToGl(User $user, CkpnJournal $ckpnJournal): bool
    {
        return $this->can($user, 'ExecuteGlToGl') && $this->canAccessRecord($user, $ckpnJournal);
    }

    private function can(User $user, string $action): bool
    {
        return $user->can("{$action}:".self::SUBJECT);
    }

    private function canAccessRecord(User $user, CkpnJournal $ckpnJournal): bool
    {
        if (RoleScope::canViewAllBranches($user)) {
            return true;
        }

        if (! RoleScope::isBranchScoped($user)) {
            return false;
        }

        $branchOfficeId = $ckpnJournal->branch_office_id ?? $ckpnJournal->ckpnWorkpaper?->branch_office_id;

        return $branchOfficeId !== null && $user->branch_office_id === $branchOfficeId;
    }
}
