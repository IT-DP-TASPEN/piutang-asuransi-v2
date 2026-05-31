<?php

namespace App\Policies;

use App\Models\CkpnCalculationRule;
use App\Models\User;

class CkpnCalculationRulePolicy
{
    private const SUBJECT = 'CkpnCalculationRule';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'ViewAny');
    }

    public function view(User $user, CkpnCalculationRule $ckpnCalculationRule): bool
    {
        return $this->can($user, 'View');
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'Create');
    }

    public function update(User $user, CkpnCalculationRule $ckpnCalculationRule): bool
    {
        return $this->can($user, 'Update');
    }

    public function delete(User $user, CkpnCalculationRule $ckpnCalculationRule): bool
    {
        return $this->can($user, 'Delete');
    }

    public function deleteAny(User $user): bool
    {
        return $this->can($user, 'DeleteAny');
    }

    public function restore(User $user, CkpnCalculationRule $ckpnCalculationRule): bool
    {
        return $this->can($user, 'Restore');
    }

    public function restoreAny(User $user): bool
    {
        return $this->can($user, 'RestoreAny');
    }

    public function forceDelete(User $user, CkpnCalculationRule $ckpnCalculationRule): bool
    {
        return $this->can($user, 'ForceDelete');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->can($user, 'ForceDeleteAny');
    }

    public function replicate(User $user, CkpnCalculationRule $ckpnCalculationRule): bool
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
