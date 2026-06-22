<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksSubjectPermissions;

class ClaimDocumentTypePolicy
{
    use ChecksSubjectPermissions;

    protected function permissionSubject(): string
    {
        return 'ClaimDocumentType';
    }
}
