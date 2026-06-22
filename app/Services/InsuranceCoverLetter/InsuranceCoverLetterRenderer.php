<?php

namespace App\Services\InsuranceCoverLetter;

use App\Data\InsuranceCoverLetter\InsuranceCoverLetterPreflightResult;
use App\Models\InsuranceCoverLetter;
use App\Models\InsuranceReceivable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;

class InsuranceCoverLetterRenderer
{
    public function render(
        InsuranceCoverLetter $letter,
        InsuranceReceivable $insuranceReceivable,
        InsuranceCoverLetterPreflightResult $preflight,
    ): string {
        return View::make($preflight->templateKey, [
            'letter' => $letter,
            'receivable' => $insuranceReceivable,
            'recipientName' => $preflight->recipientName,
            'recipientAddress' => $preflight->recipientAddress,
            'attachments' => $preflight->checklist->applicableAttachments,
            'logoUrl' => '/logo.png',
            'letterDate' => $this->date($letter->letter_date),
            'deathDate' => $this->date($insuranceReceivable->date_of_death),
            'startPeriod' => $this->date($insuranceReceivable->start_period),
            'endPeriod' => $this->date($insuranceReceivable->end_period),
            'creditLimit' => $this->money($insuranceReceivable->credit_limit),
            'claimAmount' => $this->money($insuranceReceivable->receivable_amount ?? $insuranceReceivable->loan_outstanding),
            'loanOutstanding' => $this->money($insuranceReceivable->loan_outstanding),
        ])->render();
    }

    private function date(mixed $value): string
    {
        if (blank($value)) {
            return '-';
        }

        return Carbon::parse($value)->locale('id')->translatedFormat('d F Y');
    }

    private function money(mixed $value): string
    {
        if (blank($value)) {
            return '-';
        }

        return 'Rp '.number_format((float) $value, 0, ',', '.');
    }
}
