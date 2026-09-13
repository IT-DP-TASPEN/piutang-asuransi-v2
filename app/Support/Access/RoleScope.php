<?php

namespace App\Support\Access;

use App\Models\User;

class RoleScope
{
    public const BRANCH_SCOPED_ROLES = [
        'branch_maker',
        'branch_approver',
    ];

    public const CENTRAL_ROLES = [
        'super_admin',
        'it_user',
        'accounting_maker',
        'accounting_approver',
        'insurance_approver',
        'business_maker',
        'business_approver',
        'auditor',
    ];

    public static function isBranchScoped(User $user): bool
    {
        return $user->hasAnyRole(self::BRANCH_SCOPED_ROLES);
    }

    public static function canViewAllBranches(User $user): bool
    {
        return $user->hasAnyRole(self::CENTRAL_ROLES);
    }
}
