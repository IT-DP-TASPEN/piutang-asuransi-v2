<?php

namespace App\Data\ClaimDocuments;

use App\Models\InsuranceReceivableDocument;

final readonly class ClaimDocumentChecklistItem
{
    public const STATUS_COMPLETE = 'complete';

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_MISSING = 'missing';

    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    public const STATUS_PENDING_CONDITION = 'pending_condition';

    public const STATUS_MISSING_DATA = 'missing_data';

    public function __construct(
        public string $key,
        public string $code,
        public string $name,
        public ?string $description,
        public bool $uploadable,
        public bool $required,
        public bool $conditional,
        public ?string $conditionKey,
        public ?int $documentTypeId,
        public string $status,
        public ?string $warning,
        public ?InsuranceReceivableDocument $uploadedDocument,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            '__key' => $this->key,
            'key' => $this->key,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'uploadable' => $this->uploadable,
            'required' => $this->required,
            'conditional' => $this->conditional,
            'condition_key' => $this->conditionKey,
            'document_type_id' => $this->documentTypeId,
            'status' => $this->status,
            'warning' => $this->warning,
            'uploaded_document_id' => $this->uploadedDocument?->id,
            'original_file_name' => $this->uploadedDocument?->original_file_name,
            'uploaded_by' => $this->uploadedDocument?->uploader?->name,
            'uploaded_at' => $this->uploadedDocument?->uploaded_at,
        ];
    }
}
