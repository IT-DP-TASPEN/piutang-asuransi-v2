<?php

namespace App\Data\ClaimDocuments;

use Illuminate\Support\Collection;

final readonly class ClaimDocumentChecklist
{
    /**
     * @param  list<ClaimDocumentChecklistItem>  $items
     * @param  list<string>  $warnings
     * @param  list<string>  $missingDocuments
     * @param  list<string>  $missingDataWarnings
     * @param  list<string>  $applicableAttachments
     */
    public function __construct(
        public array $items,
        public int $uploadedCount,
        public int $requiredUploadCount,
        public bool $complete,
        public array $warnings,
        public array $missingDocuments,
        public array $missingDataWarnings,
        public array $applicableAttachments,
    ) {}

    public function progressLabel(): string
    {
        return "{$this->uploadedCount}/{$this->requiredUploadCount} documents uploaded";
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function tableRecords(): Collection
    {
        return collect($this->items)->map(
            fn (ClaimDocumentChecklistItem $item): array => $item->toArray(),
        );
    }

    public function itemByDocumentTypeId(int $documentTypeId): ?ClaimDocumentChecklistItem
    {
        return collect($this->items)->first(
            fn (ClaimDocumentChecklistItem $item): bool => $item->documentTypeId === $documentTypeId,
        );
    }
}
