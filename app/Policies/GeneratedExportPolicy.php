<?php

namespace App\Policies;

use App\Models\CkpnWorkpaper;
use App\Models\GeneratedExport;
use App\Models\User;
use App\Support\Access\RoleScope;

class GeneratedExportPolicy
{
    private const SUBJECT = 'GeneratedExport';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'ViewAny');
    }

    public function view(User $user, GeneratedExport $generatedExport): bool
    {
        return $this->can($user, 'View') && $this->canAccessRecord($user, $generatedExport);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'Create');
    }

    public function update(User $user, GeneratedExport $generatedExport): bool
    {
        return $this->can($user, 'Update') && $this->canAccessRecord($user, $generatedExport);
    }

    public function delete(User $user, GeneratedExport $generatedExport): bool
    {
        return $this->can($user, 'Delete') && $this->canAccessRecord($user, $generatedExport);
    }

    public function deleteAny(User $user): bool
    {
        return $this->can($user, 'DeleteAny');
    }

    public function restore(User $user, GeneratedExport $generatedExport): bool
    {
        return $this->can($user, 'Restore') && $this->canAccessRecord($user, $generatedExport);
    }

    public function restoreAny(User $user): bool
    {
        return $this->can($user, 'RestoreAny');
    }

    public function forceDelete(User $user, GeneratedExport $generatedExport): bool
    {
        return $this->can($user, 'ForceDelete') && $this->canAccessRecord($user, $generatedExport);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->can($user, 'ForceDeleteAny');
    }

    public function replicate(User $user, GeneratedExport $generatedExport): bool
    {
        return $this->can($user, 'Replicate') && $this->canAccessRecord($user, $generatedExport);
    }

    public function reorder(User $user): bool
    {
        return $this->can($user, 'Reorder');
    }

    private function can(User $user, string $action): bool
    {
        return $user->can("{$action}:".self::SUBJECT);
    }

    private function canAccessRecord(User $user, GeneratedExport $generatedExport): bool
    {
        if (RoleScope::canViewAllBranches($user)) {
            return true;
        }

        if (! RoleScope::isBranchScoped($user)) {
            return false;
        }

        $exportable = $generatedExport->exportable;

        return $exportable instanceof CkpnWorkpaper
            && $exportable->branch_office_id !== null
            && $user->branch_office_id === $exportable->branch_office_id;
    }
}
