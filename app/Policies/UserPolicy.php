<?php

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class UserPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:User');
    }

    public function view(AuthUser $authUser): bool
    {
        return $authUser->can('View:User');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:User');
    }

    public function update(AuthUser $authUser, AuthUser $user): bool
    {
        return $authUser->can('Update:User') && $this->canManage($authUser, $user);
    }

    public function delete(AuthUser $authUser, AuthUser $user): bool
    {
        return $authUser->can('Delete:User') && $this->canManage($authUser, $user);
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:User');
    }

    public function restore(AuthUser $authUser): bool
    {
        return $authUser->can('Restore:User');
    }

    public function forceDelete(AuthUser $authUser, AuthUser $user): bool
    {
        return $authUser->can('ForceDelete:User') && $this->canManage($authUser, $user);
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:User');
    }

    /**
     * A super_admin account can only be changed by a super_admin, so other user managers cannot take it over.
     */
    private function canManage(AuthUser $authUser, AuthUser $user): bool
    {
        return ! $user->hasRole('super_admin') || $authUser->hasRole('super_admin');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:User');
    }

    public function replicate(AuthUser $authUser): bool
    {
        return $authUser->can('Replicate:User');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:User');
    }
}
