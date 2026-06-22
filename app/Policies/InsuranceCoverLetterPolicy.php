<?php

namespace App\Policies;

use App\Models\InsuranceCoverLetter;
use App\Models\User;
use App\Support\Access\RoleScope;

class InsuranceCoverLetterPolicy
{
    private const SUBJECT = 'InsuranceCoverLetter';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'ViewAny');
    }

    public function view(User $user, InsuranceCoverLetter $insuranceCoverLetter): bool
    {
        return $this->can($user, 'View') && $this->canAccessRecord($user, $insuranceCoverLetter);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'Create');
    }

    public function update(User $user, InsuranceCoverLetter $insuranceCoverLetter): bool
    {
        return false;
    }

    public function delete(User $user, InsuranceCoverLetter $insuranceCoverLetter): bool
    {
        return $this->can($user, 'Delete') && $this->canAccessRecord($user, $insuranceCoverLetter);
    }

    public function deleteAny(User $user): bool
    {
        return $this->can($user, 'DeleteAny');
    }

    public function restore(User $user, InsuranceCoverLetter $insuranceCoverLetter): bool
    {
        return $this->can($user, 'Restore') && $this->canAccessRecord($user, $insuranceCoverLetter);
    }

    public function restoreAny(User $user): bool
    {
        return $this->can($user, 'RestoreAny');
    }

    public function forceDelete(User $user, InsuranceCoverLetter $insuranceCoverLetter): bool
    {
        return $this->can($user, 'ForceDelete') && $this->canAccessRecord($user, $insuranceCoverLetter);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->can($user, 'ForceDeleteAny');
    }

    public function replicate(User $user, InsuranceCoverLetter $insuranceCoverLetter): bool
    {
        return $this->can($user, 'Replicate') && $this->canAccessRecord($user, $insuranceCoverLetter);
    }

    public function reorder(User $user): bool
    {
        return $this->can($user, 'Reorder');
    }

    public function generate(User $user, InsuranceCoverLetter $insuranceCoverLetter): bool
    {
        return $this->can($user, 'Generate') && $this->canAccessRecord($user, $insuranceCoverLetter);
    }

    private function can(User $user, string $action): bool
    {
        return $user->can("{$action}:".self::SUBJECT);
    }

    private function canAccessRecord(User $user, InsuranceCoverLetter $insuranceCoverLetter): bool
    {
        if (RoleScope::canViewAllBranches($user)) {
            return true;
        }

        if (! RoleScope::isBranchScoped($user)) {
            return false;
        }

        return $user->branch_office_id === $insuranceCoverLetter->insuranceReceivable->branch_office_id;
    }
}
