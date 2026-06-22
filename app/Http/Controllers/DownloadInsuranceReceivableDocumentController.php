<?php

namespace App\Http\Controllers;

use App\Models\InsuranceReceivableDocument;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class DownloadInsuranceReceivableDocumentController extends Controller
{
    public function __invoke(InsuranceReceivableDocument $document)
    {
        abort_unless(Gate::allows('view', $document), 403);

        abort_if(blank($document->file_path), 404);

        $disk = Storage::disk(InsuranceReceivableDocument::DISK);

        abort_unless($disk->exists($document->file_path), 404);

        return $disk->download(
            $document->file_path,
            $document->original_file_name ?: basename($document->file_path),
        );
    }
}
