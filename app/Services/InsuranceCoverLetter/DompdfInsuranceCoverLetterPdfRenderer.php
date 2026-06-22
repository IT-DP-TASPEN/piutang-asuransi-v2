<?php

namespace App\Services\InsuranceCoverLetter;

use App\Contracts\InsuranceCoverLetterPdfRenderer;
use Barryvdh\DomPDF\Facade\Pdf;

class DompdfInsuranceCoverLetterPdfRenderer implements InsuranceCoverLetterPdfRenderer
{
    public function render(string $html): string
    {
        $logoPath = 'file://'.public_path('logo.png');
        $html = str_replace(
            ['src="/logo.png"', "src='/logo.png'"],
            ["src=\"{$logoPath}\"", "src='{$logoPath}'"],
            $html,
        );

        return Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->setOption([
                'defaultFont' => 'DejaVu Sans',
                'defaultMediaType' => 'print',
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
                'isJavascriptEnabled' => false,
            ])
            ->output();
    }
}
