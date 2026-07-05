<?php

namespace App\Policies;

use App\Models\ReceivablePayment;
use App\Models\User;
use App\Support\Access\RoleScope;

class ReceivablePaymentPolicy
{
    private const SUBJECT = 'ReceivablePayment';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'ViewAny');
    }

    public function view(User $user, ReceivablePayment $receivablePayment): bool
    {
        return $this->can($user, 'View') && $this->canAccessRecord($user, $receivablePayment);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'Create');
    }

    public function update(User $user, ReceivablePayment $receivablePayment): bool
    {
        return false;
    }

    public function delete(User $user, ReceivablePayment $receivablePayment): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, ReceivablePayment $receivablePayment): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, ReceivablePayment $receivablePayment): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, ReceivablePayment $receivablePayment): bool
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

    private function canAccessRecord(User $user, ReceivablePayment $receivablePayment): bool
    {
        if (RoleScope::canViewAllBranches($user)) {
            return true;
        }

        if (! RoleScope::isBranchScoped($user)) {
            return false;
        }

        $insuranceBranchOfficeId = $receivablePayment->insuranceReceivable?->branch_office_id;

        return $user->branch_office_id === $insuranceBranchOfficeId;
    }
}
