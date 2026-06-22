<?php

namespace App\Actions\InsuranceReceivable;

use App\Data\ClaimDocuments\ClaimDocumentChecklistItem;
use App\Models\InsuranceReceivable;
use App\Models\InsuranceReceivableDocument;
use App\Models\User;
use App\Services\InsuranceReceivable\ResolveClaimDocumentChecklist;
use Illuminate\Validation\ValidationException;

class StoreClaimDocumentAction
{
    public function __construct(
        private readonly ResolveClaimDocumentChecklist $checklistResolver,
    ) {}

    public function handle(
        InsuranceReceivable $insuranceReceivable,
        int $claimDocumentTypeId,
        string $filePath,
        ?string $originalFileName,
        User $user,
    ): InsuranceReceivableDocument {
        $item = $this->checklistResolver
            ->handle($insuranceReceivable)
            ->itemByDocumentTypeId($claimDocumentTypeId);

        if (! $item instanceof ClaimDocumentChecklistItem || ! $item->uploadable) {
            throw ValidationException::withMessages([
                'document' => 'Document type is not part of this receivable checklist.',
            ]);
        }

        if (in_array($item->status, [
            ClaimDocumentChecklistItem::STATUS_NOT_APPLICABLE,
            ClaimDocumentChecklistItem::STATUS_PENDING_CONDITION,
        ], true)) {
            throw ValidationException::withMessages([
                'document' => 'Document is not currently applicable to this receivable.',
            ]);
        }

        return $insuranceReceivable->documents()->updateOrCreate(
            ['claim_document_type_id' => $claimDocumentTypeId],
            [
                'file_path' => $filePath,
                'original_file_name' => $originalFileName,
                'mime_type' => 'application/pdf',
                'uploaded_by' => $user->id,
                'uploaded_at' => now(),
            ],
        );
    }
}
