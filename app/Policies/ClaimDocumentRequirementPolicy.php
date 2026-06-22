<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksSubjectPermissions;

class ClaimDocumentRequirementPolicy
{
    use ChecksSubjectPermissions;

    protected function permissionSubject(): string
    {
        return 'ClaimDocumentRequirement';
    }
}
