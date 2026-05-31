<?php

namespace App\Policies;

use App\Models\InsuranceReceivableDocument;
use App\Models\User;
use App\Support\Access\RoleScope;

class InsuranceReceivableDocumentPolicy
{
    private const SUBJECT = 'InsuranceReceivableDocument';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'ViewAny');
    }

    public function view(User $user, InsuranceReceivableDocument $insuranceReceivableDocument): bool
    {
        return $this->can($user, 'View') && $this->canAccessRecord($user, $insuranceReceivableDocument);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'Create');
    }

    public function update(User $user, InsuranceReceivableDocument $insuranceReceivableDocument): bool
    {
        return $this->can($user, 'Update') && $this->canAccessRecord($user, $insuranceReceivableDocument);
    }

    public function delete(User $user, InsuranceReceivableDocument $insuranceReceivableDocument): bool
    {
        return $this->can($user, 'Delete') && $this->canAccessRecord($user, $insuranceReceivableDocument);
    }

    public function deleteAny(User $user): bool
    {
        return $this->can($user, 'DeleteAny');
    }

    public function restore(User $user, InsuranceReceivableDocument $insuranceReceivableDocument): bool
    {
        return $this->can($user, 'Restore') && $this->canAccessRecord($user, $insuranceReceivableDocument);
    }

    public function restoreAny(User $user): bool
    {
        return $this->can($user, 'RestoreAny');
    }

    public function forceDelete(User $user, InsuranceReceivableDocument $insuranceReceivableDocument): bool
    {
        return $this->can($user, 'ForceDelete') && $this->canAccessRecord($user, $insuranceReceivableDocument);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->can($user, 'ForceDeleteAny');
    }

    public function replicate(User $user, InsuranceReceivableDocument $insuranceReceivableDocument): bool
    {
        return $this->can($user, 'Replicate') && $this->canAccessRecord($user, $insuranceReceivableDocument);
    }

    public function reorder(User $user): bool
    {
        return $this->can($user, 'Reorder');
    }

    private function can(User $user, string $action): bool
    {
        return $user->can("{$action}:".self::SUBJECT);
    }

    private function canAccessRecord(User $user, InsuranceReceivableDocument $insuranceReceivableDocument): bool
    {
        if (RoleScope::canViewAllBranches($user)) {
            return true;
        }

        if (! RoleScope::isBranchScoped($user)) {
            return false;
        }

        return $user->branch_office_id === $insuranceReceivableDocument->insuranceReceivable?->branch_office_id;
    }
}
