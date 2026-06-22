<?php

namespace App\Data\InsuranceCoverLetter;

use App\Data\ClaimDocuments\ClaimDocumentChecklist;

final readonly class InsuranceCoverLetterPreflightResult
{
    /** @param list<string> $warnings */
    public function __construct(
        public string $claimType,
        public string $templateKey,
        public string $recipientName,
        public ?string $recipientAddress,
        public ClaimDocumentChecklist $checklist,
        public array $warnings,
    ) {}
}
