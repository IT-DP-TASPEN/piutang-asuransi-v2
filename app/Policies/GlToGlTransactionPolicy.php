<?php

namespace App\Policies;

use App\Models\GlToGlTransaction;
use App\Models\User;

class GlToGlTransactionPolicy
{
    private const SUBJECT = 'GlToGlTransaction';

    public function viewAny(User $user): bool
    {
        return $this->canAccessResource($user) && $this->can($user, 'ViewAny');
    }

    public function view(User $user, GlToGlTransaction $glToGlTransaction): bool
    {
        return $this->canAccessResource($user) && $this->can($user, 'View');
    }

    public function create(User $user): bool
    {
        return $this->canAccessResource($user) && $this->can($user, 'Create');
    }

    public function update(User $user, GlToGlTransaction $glToGlTransaction): bool
    {
        return $this->canAccessResource($user) && $this->can($user, 'Update');
    }

    public function delete(User $user, GlToGlTransaction $glToGlTransaction): bool
    {
        return $this->canAccessResource($user) && $this->can($user, 'Delete');
    }

    public function deleteAny(User $user): bool
    {
        return $this->canAccessResource($user) && $this->can($user, 'DeleteAny');
    }

    public function restore(User $user, GlToGlTransaction $glToGlTransaction): bool
    {
        return $this->canAccessResource($user) && $this->can($user, 'Restore');
    }

    public function restoreAny(User $user): bool
    {
        return $this->canAccessResource($user) && $this->can($user, 'RestoreAny');
    }

    public function forceDelete(User $user, GlToGlTransaction $glToGlTransaction): bool
    {
        return $this->canAccessResource($user) && $this->can($user, 'ForceDelete');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->canAccessResource($user) && $this->can($user, 'ForceDeleteAny');
    }

    public function replicate(User $user, GlToGlTransaction $glToGlTransaction): bool
    {
        return $this->canAccessResource($user) && $this->can($user, 'Replicate');
    }

    public function reorder(User $user): bool
    {
        return $this->canAccessResource($user) && $this->can($user, 'Reorder');
    }

    private function can(User $user, string $action): bool
    {
        return $user->can("{$action}:".self::SUBJECT);
    }

    private function canAccessResource(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'auditor']);
    }
}
