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
        if ($insuranceReceivable->isLegacyOrigin()) {
            throw ValidationException::withMessages([
                'origin_type' => 'Legacy receivables do not use claim documents.',
            ]);
        }

        // The path comes from the client; it must be a fresh upload, never an existing file.
        if (! str_starts_with($filePath, 'insurance-receivable-documents/')
            || str_contains($filePath, '..')
            || InsuranceReceivableDocument::query()->where('file_path', $filePath)->exists()) {
            throw ValidationException::withMessages([
                'file_path' => 'Invalid claim document file.',
            ]);
        }

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

        if (! $insuranceReceivable->allowsClaimDocumentUpload($item->status)) {
            throw ValidationException::withMessages([
                'document' => 'Claim documents are locked at this stage. After submission only missing documents can be added, until the cover letter is generated.',
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
