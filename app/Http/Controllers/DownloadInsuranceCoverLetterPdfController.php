<?php

namespace App\Http\Controllers;

use App\Models\InsuranceCoverLetter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class DownloadInsuranceCoverLetterPdfController extends Controller
{
    public function __invoke(InsuranceCoverLetter $letter)
    {
        abort_unless(Gate::allows('view', $letter), 403);
        abort_if(blank($letter->generated_file_path), 404);

        $disk = Storage::disk((string) config('insurance_cover_letters.disk', 'local'));
        abort_unless($disk->exists($letter->generated_file_path), 404);

        return $disk->download(
            $letter->generated_file_path,
            "insurance-cover-letter-{$letter->id}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }
}
