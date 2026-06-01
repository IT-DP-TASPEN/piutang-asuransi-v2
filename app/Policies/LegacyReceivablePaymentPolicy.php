<?php

namespace App\Policies;

use App\Models\LegacyReceivablePayment;
use App\Models\User;
use App\Support\Access\RoleScope;

class LegacyReceivablePaymentPolicy
{
    private const SUBJECT = 'LegacyReceivablePayment';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'ViewAny');
    }

    public function view(User $user, LegacyReceivablePayment $legacyReceivablePayment): bool
    {
        return $this->can($user, 'View') && $this->canAccessRecord($user, $legacyReceivablePayment);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'Create');
    }

    public function update(User $user, LegacyReceivablePayment $legacyReceivablePayment): bool
    {
        return $this->can($user, 'Update') && $this->canAccessRecord($user, $legacyReceivablePayment);
    }

    public function delete(User $user, LegacyReceivablePayment $legacyReceivablePayment): bool
    {
        return $this->can($user, 'Delete') && $this->canAccessRecord($user, $legacyReceivablePayment);
    }

    public function deleteAny(User $user): bool
    {
        return $this->can($user, 'DeleteAny');
    }

    public function restore(User $user, LegacyReceivablePayment $legacyReceivablePayment): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, LegacyReceivablePayment $legacyReceivablePayment): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, LegacyReceivablePayment $legacyReceivablePayment): bool
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

    private function canAccessRecord(User $user, LegacyReceivablePayment $legacyReceivablePayment): bool
    {
        if (RoleScope::canViewAllBranches($user)) {
            return true;
        }

        if (! RoleScope::isBranchScoped($user)) {
            return false;
        }

        return $user->branch_office_id === $legacyReceivablePayment->legacyReceivable->branch_office_id;
    }
}
