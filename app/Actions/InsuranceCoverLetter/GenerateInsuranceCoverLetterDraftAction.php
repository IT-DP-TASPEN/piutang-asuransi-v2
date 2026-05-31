<?php

namespace App\Actions\InsuranceCoverLetter;

use App\Models\InsuranceCoverLetter;
use App\Models\InsuranceReceivable;
use App\Models\User;

class GenerateInsuranceCoverLetterDraftAction
{
    public function handle(InsuranceReceivable $insuranceReceivable, User $user): InsuranceCoverLetter
    {
        return $insuranceReceivable->insuranceCoverLetters()->create([
            'letter_date' => now()->toDateString(),
            'insurance_company_id' => $insuranceReceivable->insurance_company_id,
            'recipient_name' => $insuranceReceivable->insuranceCompany->name,
            'subject' => "Pengajuan Klaim Asuransi {$insuranceReceivable->loan_account_number}",
            'body' => $this->body($insuranceReceivable),
            'status' => InsuranceCoverLetter::STATUS_DRAFT,
            'created_by' => $user->id,
        ]);
    }

    private function body(InsuranceReceivable $insuranceReceivable): string
    {
        $customerName = $insuranceReceivable->customer_name ?: '-';
        $dateOfDeath = $insuranceReceivable->date_of_death?->toDateString() ?: '-';
        $outstanding = $insuranceReceivable->loan_outstanding ?: '-';
        $insuranceCompany = $insuranceReceivable->insuranceCompany->name;

        return <<<BODY
Kepada Yth. {$insuranceCompany}

Bersama ini kami sampaikan pengajuan klaim asuransi untuk debitur {$customerName} dengan nomor rekening {$insuranceReceivable->loan_account_number}.

Tanggal meninggal: {$dateOfDeath}
Nilai outstanding: {$outstanding}

Hormat kami,
BODY;
    }
}
