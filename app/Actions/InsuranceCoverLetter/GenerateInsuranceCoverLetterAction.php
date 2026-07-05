<?php

namespace App\Actions\InsuranceCoverLetter;

use App\Models\InsuranceCompany;
use App\Models\InsuranceCoverLetter;
use App\Models\InsuranceCoverLetterSetting;
use App\Models\InsuranceReceivable;
use App\Models\User;
use App\Services\InsuranceCoverLetter\InsuranceCoverLetterPreflight;
use App\Services\InsuranceCoverLetter\InsuranceCoverLetterRenderer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateInsuranceCoverLetterAction
{
    public function __construct(
        private readonly InsuranceCoverLetterPreflight $preflight,
        private readonly InsuranceCoverLetterRenderer $renderer,
        private readonly EnsureInsuranceCoverLetterPdfAction $ensurePdf,
    ) {}

    public function handle(
        InsuranceReceivable $insuranceReceivable,
        User $user,
        mixed $letterDate = null,
    ): InsuranceCoverLetter {
        if ($insuranceReceivable->isLegacyOrigin()) {
            throw ValidationException::withMessages([
                'origin_type' => 'Legacy receivables cannot generate insurance cover letters.',
            ]);
        }

        try {
            $letter = DB::transaction(function () use ($insuranceReceivable, $user, $letterDate): InsuranceCoverLetter {
                $receivable = InsuranceReceivable::query()
                    ->with(['insuranceCompany', 'branchOffice'])
                    ->whereKey($insuranceReceivable->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $company = $receivable->insuranceCompany;

                if (! $company instanceof InsuranceCompany || ! array_key_exists($company->claim_type, InsuranceCompany::claimTypeOptions())) {
                    throw ValidationException::withMessages([
                        'claim_type' => 'A valid insurance company claim type is required.',
                    ]);
                }

                $existing = $receivable->insuranceCoverLetters()
                    ->where('claim_type', $company->claim_type)
                    ->first();

                if ($existing instanceof InsuranceCoverLetter) {
                    return $existing;
                }

                $preflight = $this->preflight->handle($receivable);
                $date = $letterDate === null ? now() : Carbon::parse($letterDate);
                $sequenceBase = InsuranceCoverLetterSetting::sequenceBase();

                $letter = $receivable->insuranceCoverLetters()->create([
                    'claim_type' => $preflight->claimType,
                    'letter_date' => $date->toDateString(),
                    'template_key' => $preflight->templateKey,
                    'insurance_company_id' => $company->id,
                    'recipient_name' => $preflight->recipientName,
                    'recipient_address' => $preflight->recipientAddress,
                    'subject' => "Pengajuan Klaim Asuransi {$receivable->loan_account_number}",
                    'sequence_base' => $sequenceBase,
                    'status' => InsuranceCoverLetter::STATUS_DRAFT,
                    'created_by' => $user->id,
                ]);

                $sequenceNumber = $sequenceBase + $letter->id;
                $letter->forceFill([
                    'sequence_number' => $sequenceNumber,
                    'letter_number' => sprintf('SRT – %d/B.01.1/%s', $sequenceNumber, $date->format('mY')),
                ])->save();

                $letter->forceFill([
                    'rendered_html' => $this->renderer->render($letter, $receivable, $preflight),
                    'status' => InsuranceCoverLetter::STATUS_GENERATED,
                ])->save();

                return $letter->refresh();
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'letter_number' => 'Cover letter sequence collides with an existing number. Update the global sequence base.',
            ]);
        }

        return $this->ensurePdf->handle($letter);
    }
}
