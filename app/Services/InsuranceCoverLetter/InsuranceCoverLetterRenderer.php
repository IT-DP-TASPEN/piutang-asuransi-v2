<?php

namespace App\Services\InsuranceCoverLetter;

use App\Data\InsuranceCoverLetter\InsuranceCoverLetterPreflightResult;
use App\Models\InsuranceCoverLetter;
use App\Models\InsuranceReceivable;
use Illuminate\Support\Carbon;
use RuntimeException;

class InsuranceCoverLetterRenderer
{
    public function render(
        InsuranceCoverLetter $letter,
        InsuranceReceivable $insuranceReceivable,
        InsuranceCoverLetterPreflightResult $preflight,
    ): string {
        $html = $this->readTemplate($preflight->templatePath);
        $html = $this->selectSection($html, $preflight->templateSectionId);
        $html = $this->replaceLogo($html);
        $html = $this->replaceLetterNumber($html, $letter->letter_number);

        $claimAmount = $insuranceReceivable->receivable_amount;

        if (blank($claimAmount)) {
            $claimAmount = $insuranceReceivable->loan_outstanding;
        }

        foreach ([
            'Date Create' => $this->date($letter->letter_date),
            'Nama Perusahaan Asuransi' => $preflight->recipientName,
            'Alamat' => $preflight->recipientAddress,
            'Nama Debitur' => $insuranceReceivable->customer_name,
            'Plafond' => $this->money($insuranceReceivable->credit_limit),
            'Sisa Baki Debet' => $this->money($claimAmount),
            'Tgl Realisasi' => $this->date($insuranceReceivable->start_period),
            'Tgl Jth Tempo' => $this->date($insuranceReceivable->end_period),
            'tanggal meninggal' => $this->date($insuranceReceivable->date_of_death),
        ] as $placeholder => $value) {
            $html = $this->replacePlaceholder($html, $placeholder, $this->text($value));
        }

        return $this->stripHighlightSpans($html);
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

    private function readTemplate(string $templatePath): string
    {
        $absolutePath = str_starts_with($templatePath, DIRECTORY_SEPARATOR)
            ? $templatePath
            : base_path($templatePath);

        $html = file_get_contents($absolutePath);

        if ($html === false) {
            throw new RuntimeException("Unable to read insurance cover letter template [{$templatePath}].");
        }

        return $html;
    }

    private function selectSection(string $html, string $sectionId): string
    {
        $pattern = '/<section\b[^>]*\bid="'.preg_quote($sectionId, '/').'"[^>]*>.*?<\/section>/s';

        if (! preg_match($pattern, $html, $sectionMatch)) {
            throw new RuntimeException("Insurance cover letter template section [{$sectionId}] is unavailable.");
        }

        if (! preg_match('/^(.*?<body[^>]*>).*?(<\/body>.*)$/s', $html, $bodyMatch)) {
            return $sectionMatch[0];
        }

        return $bodyMatch[1]."\n\n".$sectionMatch[0]."\n\n".$bodyMatch[2];
    }

    private function replaceLogo(string $html): string
    {
        return (string) preg_replace(
            '/(<img\b[^>]*\bclass="logo"[^>]*\bsrc=")data:image\/[^"]+("[^>]*>)/s',
            '$1/logo.png$2',
            $html,
            1,
        );
    }

    private function replaceLetterNumber(string $html, mixed $letterNumber): string
    {
        $escapedLetterNumber = e($this->text($letterNumber));

        return str_replace(
            [
                'SRT &ndash; ....../B.01.1/<span class="highlight">mmyyyy</span>',
                '<strong><span class="highlight">SRT &ndash; xxxx/B.01.1/mmyyyy</span></strong>',
            ],
            [
                $escapedLetterNumber,
                "<strong>{$escapedLetterNumber}</strong>",
            ],
            $html,
        );
    }

    private function replacePlaceholder(string $html, string $placeholder, string $value): string
    {
        $escapedValue = e($value);
        $encodedPlaceholder = '&lt;&lt;'.$placeholder.'&gt;&gt;';

        return str_replace(
            [
                '<span class="highlight">'.$encodedPlaceholder.'</span>',
                $encodedPlaceholder,
            ],
            $escapedValue,
            $html,
        );
    }

    private function stripHighlightSpans(string $html): string
    {
        return (string) preg_replace('/<span class="highlight">(.*?)<\/span>/s', '$1', $html);
    }

    private function text(mixed $value): string
    {
        if (blank($value)) {
            return '-';
        }

        return (string) $value;
    }
}
