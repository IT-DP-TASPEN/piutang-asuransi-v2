<?php

namespace App\Policies;

use App\Models\InsuranceCompany;
use App\Models\User;

class InsuranceCompanyPolicy
{
    private const SUBJECT = 'InsuranceCompany';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'ViewAny');
    }

    public function view(User $user, InsuranceCompany $insuranceCompany): bool
    {
        return $this->can($user, 'View');
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'Create');
    }

    public function update(User $user, InsuranceCompany $insuranceCompany): bool
    {
        return $this->can($user, 'Update');
    }

    public function delete(User $user, InsuranceCompany $insuranceCompany): bool
    {
        return $this->can($user, 'Delete');
    }

    public function deleteAny(User $user): bool
    {
        return $this->can($user, 'DeleteAny');
    }

    public function restore(User $user, InsuranceCompany $insuranceCompany): bool
    {
        return $this->can($user, 'Restore');
    }

    public function restoreAny(User $user): bool
    {
        return $this->can($user, 'RestoreAny');
    }

    public function forceDelete(User $user, InsuranceCompany $insuranceCompany): bool
    {
        return $this->can($user, 'ForceDelete');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->can($user, 'ForceDeleteAny');
    }

    public function replicate(User $user, InsuranceCompany $insuranceCompany): bool
    {
        return $this->can($user, 'Replicate');
    }

    public function reorder(User $user): bool
    {
        return $this->can($user, 'Reorder');
    }

    private function can(User $user, string $action): bool
    {
        return $user->can("{$action}:".self::SUBJECT);
    }
}
