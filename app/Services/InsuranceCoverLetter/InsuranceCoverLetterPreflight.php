<?php

namespace App\Services\InsuranceCoverLetter;

use App\Data\InsuranceCoverLetter\InsuranceCoverLetterPreflightResult;
use App\Models\InsuranceCompany;
use App\Models\InsuranceReceivable;
use App\Services\InsuranceReceivable\ResolveClaimDocumentChecklist;
use Illuminate\Validation\ValidationException;

class InsuranceCoverLetterPreflight
{
    public function __construct(
        private readonly ResolveClaimDocumentChecklist $checklistResolver,
    ) {}

    public function handle(InsuranceReceivable $insuranceReceivable): InsuranceCoverLetterPreflightResult
    {
        if ($insuranceReceivable->isLegacyOrigin()) {
            throw ValidationException::withMessages([
                'origin_type' => 'Legacy receivables cannot generate insurance cover letters.',
            ]);
        }

        $insuranceReceivable->loadMissing(['insuranceCompany', 'branchOffice']);
        $company = $insuranceReceivable->insuranceCompany;

        if (! $company instanceof InsuranceCompany) {
            throw ValidationException::withMessages([
                'insurance_company' => 'Insurance company is required before generating a cover letter.',
            ]);
        }

        $claimType = $company->claim_type;
        $template = config("insurance_cover_letters.templates.{$claimType}");

        if (! array_key_exists($claimType, InsuranceCompany::claimTypeOptions())) {
            throw ValidationException::withMessages([
                'claim_type' => 'Insurance company claim type must be ajk or credit.',
            ]);
        }

        $view = is_array($template) && is_string($template['view'] ?? null) ? trim($template['view']) : '';

        if ($view === '' || ! view()->exists($view)) {
            throw ValidationException::withMessages([
                'template' => "Cover letter template for claim type {$claimType} is unavailable.",
            ]);
        }

        // Nominal Klaim is the Contract Outstanding, which Accounting Validation fetches from Core.
        if (blank($insuranceReceivable->contract_outstanding_amount)) {
            throw ValidationException::withMessages([
                'contract_outstanding' => 'Contract Outstanding is not available yet. Generate the cover letter after Accounting Validation has fetched it.',
            ]);
        }

        $checklist = $this->checklistResolver->handle($insuranceReceivable);
        $warnings = $checklist->warnings;

        if (blank($company->letter_recipient_address)) {
            $warnings[] = 'Insurance company recipient address is incomplete.';
        }

        $optionalFields = [
            'customer_name' => 'Customer name is unavailable.',
            'credit_limit' => 'Credit limit/plafond is unavailable.',
            'start_period' => 'Credit start period is unavailable.',
            'end_period' => 'Credit end period is unavailable.',
        ];

        foreach ($optionalFields as $field => $warning) {
            if (blank($insuranceReceivable->{$field})) {
                $warnings[] = $warning;
            }
        }

        return new InsuranceCoverLetterPreflightResult(
            claimType: $claimType,
            templateKey: $view,
            view: $view,
            recipientName: $company->resolvedLetterRecipientName(),
            recipientAddress: $company->letter_recipient_address,
            checklist: $checklist,
            warnings: array_values(array_unique($warnings)),
        );
    }
}
