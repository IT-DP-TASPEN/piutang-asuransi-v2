<?php

namespace App\Policies\Concerns;

use App\Models\User;

trait ChecksSubjectPermissions
{
    abstract protected function permissionSubject(): string;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:'.$this->permissionSubject());
    }

    public function view(User $user): bool
    {
        return $user->can('View:'.$this->permissionSubject());
    }

    public function create(User $user): bool
    {
        return $user->can('Create:'.$this->permissionSubject());
    }

    public function update(User $user): bool
    {
        return $user->can('Update:'.$this->permissionSubject());
    }

    public function delete(User $user): bool
    {
        return $user->can('Delete:'.$this->permissionSubject());
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:'.$this->permissionSubject());
    }
}
