<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksSubjectPermissions;

class InsuranceCoverLetterSettingPolicy
{
    use ChecksSubjectPermissions;

    protected function permissionSubject(): string
    {
        return 'InsuranceCoverLetterSetting';
    }
}
