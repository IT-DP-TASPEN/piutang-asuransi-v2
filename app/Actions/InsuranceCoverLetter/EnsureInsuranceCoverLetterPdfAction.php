<?php

namespace App\Actions\InsuranceCoverLetter;

use App\Contracts\InsuranceCoverLetterPdfRenderer;
use App\Models\InsuranceCoverLetter;
use Illuminate\Support\Facades\Storage;
use Throwable;

class EnsureInsuranceCoverLetterPdfAction
{
    public function __construct(
        private readonly InsuranceCoverLetterPdfRenderer $pdfRenderer,
    ) {}

    public function handle(InsuranceCoverLetter $letter): InsuranceCoverLetter
    {
        $diskName = (string) config('insurance_cover_letters.disk', 'local');
        $disk = Storage::disk($diskName);

        if (filled($letter->generated_file_path) && $disk->exists($letter->generated_file_path)) {
            return $letter;
        }

        if (blank($letter->rendered_html)) {
            return $letter;
        }

        try {
            $path = trim((string) config('insurance_cover_letters.directory', 'insurance-cover-letters'), '/')
                ."/insurance-cover-letter-{$letter->id}.pdf";
            $disk->put($path, $this->pdfRenderer->render($letter->rendered_html));
            $letter->forceFill(['generated_file_path' => $path])->save();
        } catch (Throwable $exception) {
            report($exception);
        }

        return $letter->refresh();
    }
}
