<?php

namespace App\Policies;

use App\Models\LegacyReceivable;
use App\Models\User;
use App\Support\Access\RoleScope;

class LegacyReceivablePolicy
{
    private const SUBJECT = 'LegacyReceivable';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'ViewAny');
    }

    public function view(User $user, LegacyReceivable $legacyReceivable): bool
    {
        return $this->can($user, 'View') && $this->canAccessRecord($user, $legacyReceivable);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'Create');
    }

    public function update(User $user, LegacyReceivable $legacyReceivable): bool
    {
        return $this->can($user, 'Update') && $this->canAccessRecord($user, $legacyReceivable);
    }

    public function delete(User $user, LegacyReceivable $legacyReceivable): bool
    {
        return $this->can($user, 'Delete') && $this->canAccessRecord($user, $legacyReceivable);
    }

    public function deleteAny(User $user): bool
    {
        return $this->can($user, 'DeleteAny');
    }

    public function restore(User $user, LegacyReceivable $legacyReceivable): bool
    {
        return $this->can($user, 'Restore') && $this->canAccessRecord($user, $legacyReceivable);
    }

    public function restoreAny(User $user): bool
    {
        return $this->can($user, 'RestoreAny');
    }

    public function forceDelete(User $user, LegacyReceivable $legacyReceivable): bool
    {
        return $this->can($user, 'ForceDelete') && $this->canAccessRecord($user, $legacyReceivable);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->can($user, 'ForceDeleteAny');
    }

    public function replicate(User $user, LegacyReceivable $legacyReceivable): bool
    {
        return $this->can($user, 'Replicate') && $this->canAccessRecord($user, $legacyReceivable);
    }

    public function reorder(User $user): bool
    {
        return $this->can($user, 'Reorder');
    }

    private function can(User $user, string $action): bool
    {
        return $user->can("{$action}:".self::SUBJECT);
    }

    private function canAccessRecord(User $user, LegacyReceivable $legacyReceivable): bool
    {
        if (RoleScope::canViewAllBranches($user)) {
            return true;
        }

        if (! RoleScope::isBranchScoped($user)) {
            return false;
        }

        return $user->branch_office_id === $legacyReceivable->branch_office_id;
    }
}
