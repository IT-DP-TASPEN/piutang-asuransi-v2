<?php

namespace App\Services\InsuranceReceivable;

use App\Data\ClaimDocuments\ClaimDocumentChecklist;
use App\Data\ClaimDocuments\ClaimDocumentChecklistItem;
use App\Models\ClaimDocumentRequirement;
use App\Models\InsuranceCompany;
use App\Models\InsuranceReceivable;
use App\Models\InsuranceReceivableDocument;
use Illuminate\Database\Eloquent\Collection;

class ResolveClaimDocumentChecklist
{
    public function handle(InsuranceReceivable $insuranceReceivable): ClaimDocumentChecklist
    {
        $insuranceReceivable->loadMissing([
            'insuranceCompany',
            'documents.claimDocumentType',
            'documents.uploader',
        ]);

        $items = [$this->dateOfDeathItem($insuranceReceivable)];
        $warnings = [];
        $missingDataWarnings = [];

        if ($insuranceReceivable->date_of_death === null) {
            $missingDataWarnings[] = 'Tanggal Debitur Meninggal belum diisi.';
        }

        $claimType = $insuranceReceivable->insuranceCompany?->claim_type;

        if (! array_key_exists((string) $claimType, InsuranceCompany::claimTypeOptions())) {
            $warnings[] = 'Insurance company claim type is unavailable.';
            $warnings = [...$missingDataWarnings, ...$warnings];

            return new ClaimDocumentChecklist(
                items: $items,
                uploadedCount: 0,
                requiredUploadCount: 0,
                complete: false,
                warnings: $warnings,
                missingDocuments: [],
                missingDataWarnings: $missingDataWarnings,
                applicableAttachments: [],
            );
        }

        /** @var Collection<int, ClaimDocumentRequirement> $requirements */
        $requirements = ClaimDocumentRequirement::query()
            ->with('claimDocumentType')
            ->where('claim_type', $claimType)
            ->whereHas('claimDocumentType', fn($query) => $query->where('active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $documents = $insuranceReceivable->documents->keyBy('claim_document_type_id');
        $condition = $insuranceReceivable->death_document_condition;
        $hasConditionalRequirements = $requirements->contains('is_conditional', true);
        $validConditionKeys = $requirements->where('is_conditional', true)->pluck('condition_key');
        $conditionSelected = filled($condition) && $validConditionKeys->contains($condition);
        $unconditionalCount = $requirements->where('is_required', true)->where('is_conditional', false)->count();
        $requiredUploadCount = $unconditionalCount;
        $uploadedCount = 0;
        $missingDocuments = [];
        $applicableAttachments = [];

        if ($hasConditionalRequirements) {
            $requiredUploadCount++;
        }

        foreach ($requirements as $requirement) {
            $documentType = $requirement->claimDocumentType;
            $document = $documents->get($documentType->id);
            assert($document === null || $document instanceof InsuranceReceivableDocument);

            $status = ClaimDocumentChecklistItem::STATUS_MISSING;
            $warning = null;

            if ($requirement->is_conditional && ! $conditionSelected) {
                $status = ClaimDocumentChecklistItem::STATUS_PENDING_CONDITION;
                $warning = 'Select death document condition to evaluate this requirement.';
            } elseif ($requirement->is_conditional && $requirement->condition_key !== $condition) {
                $status = ClaimDocumentChecklistItem::STATUS_NOT_APPLICABLE;
            } elseif (filled($document?->file_path)) {
                $status = ClaimDocumentChecklistItem::STATUS_UPLOADED;
                $uploadedCount++;
                $applicableAttachments[] = $documentType->name;
            } else {
                $missingDocuments[] = $documentType->name;
                $applicableAttachments[] = $documentType->name;
            }

            $items[] = new ClaimDocumentChecklistItem(
                key: "document:{$documentType->id}",
                code: $documentType->code,
                name: $documentType->name,
                description: $documentType->description,
                uploadable: true,
                required: $requirement->is_required,
                conditional: $requirement->is_conditional,
                conditionKey: $requirement->condition_key,
                documentTypeId: $documentType->id,
                status: $status,
                warning: $warning,
                uploadedDocument: $document,
            );
        }

        if ($hasConditionalRequirements && ! $conditionSelected) {
            $warnings[] = 'Death document condition must be selected before conditional documents can be fully evaluated.';
        }

        if ($missingDocuments !== []) {
            $warnings[] = count($missingDocuments) . ' required claim document(s) are missing.';
        }

        $warnings = [...$missingDataWarnings, ...$warnings];

        return new ClaimDocumentChecklist(
            items: $items,
            uploadedCount: $uploadedCount,
            requiredUploadCount: $requiredUploadCount,
            complete: $warnings === [],
            warnings: $warnings,
            missingDocuments: $missingDocuments,
            missingDataWarnings: $missingDataWarnings,
            applicableAttachments: $applicableAttachments,
        );
    }

    private function dateOfDeathItem(InsuranceReceivable $insuranceReceivable): ClaimDocumentChecklistItem
    {
        $complete = $insuranceReceivable->date_of_death !== null;

        return new ClaimDocumentChecklistItem(
            key: 'data:date_of_death',
            code: 'date_of_death',
            name: 'Tanggal Debitur Meninggal',
            description: null,
            uploadable: false,
            required: true,
            conditional: false,
            conditionKey: null,
            documentTypeId: null,
            status: $complete
                ? ClaimDocumentChecklistItem::STATUS_COMPLETE
                : ClaimDocumentChecklistItem::STATUS_MISSING_DATA,
            warning: $complete ? null : 'Tanggal Debitur Meninggal belum diisi.',
            uploadedDocument: null,
        );
    }
}
