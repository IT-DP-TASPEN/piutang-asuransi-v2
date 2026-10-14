<?php

namespace App\Services\InsuranceCoverLetter;

use App\Data\InsuranceCoverLetter\InsuranceCoverLetterPreflightResult;
use App\Models\InsuranceCoverLetter;
use App\Models\InsuranceReceivable;
use Illuminate\Support\Carbon;

class InsuranceCoverLetterRenderer
{
    public function render(
        InsuranceCoverLetter $letter,
        InsuranceReceivable $insuranceReceivable,
        InsuranceCoverLetterPreflightResult $preflight,
    ): string {
        $claimAmount = $insuranceReceivable->receivable_amount;

        if (blank($claimAmount)) {
            $claimAmount = $insuranceReceivable->loan_outstanding;
        }

        return view($preflight->view, [
            'letterDate' => $this->date($letter->letter_date),
            'letterNumber' => $this->text($letter->letter_number),
            'recipientName' => $this->text($preflight->recipientName),
            'recipientAddress' => $this->text($preflight->recipientAddress),
            'customerName' => $this->text($insuranceReceivable->customer_name),
            'creditLimit' => $this->money($insuranceReceivable->credit_limit),
            'claimAmount' => $this->money($claimAmount),
            'startPeriod' => $this->date($insuranceReceivable->start_period),
            'endPeriod' => $this->date($insuranceReceivable->end_period),
            'dateOfDeath' => $this->date($insuranceReceivable->date_of_death),
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

    private function text(mixed $value): string
    {
        if (blank($value)) {
            return '-';
        }

        return (string) $value;
    }
}
