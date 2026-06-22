<?php

namespace App\Contracts;

interface InsuranceCoverLetterPdfRenderer
{
    public function render(string $html): string;
}
