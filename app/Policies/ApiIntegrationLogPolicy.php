<?php

namespace App\Policies;

use App\Models\ApiIntegrationLog;
use App\Models\User;

class ApiIntegrationLogPolicy
{
    private const SUBJECT = 'ApiIntegrationLog';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'ViewAny');
    }

    public function view(User $user, ApiIntegrationLog $apiIntegrationLog): bool
    {
        return $this->can($user, 'View');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ApiIntegrationLog $apiIntegrationLog): bool
    {
        return false;
    }

    public function delete(User $user, ApiIntegrationLog $apiIntegrationLog): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, ApiIntegrationLog $apiIntegrationLog): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, ApiIntegrationLog $apiIntegrationLog): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, ApiIntegrationLog $apiIntegrationLog): bool
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
}
