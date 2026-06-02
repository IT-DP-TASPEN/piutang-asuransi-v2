<?php

namespace App\Http\Controllers;

use App\Models\GeneratedExport;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class DownloadGeneratedExportController extends Controller
{
    public function __invoke(GeneratedExport $export)
    {
        abort_unless(Gate::allows('view', $export), 403);

        abort_if($export->status !== GeneratedExport::STATUS_GENERATED || blank($export->file_path), 404);

        $disk = Storage::disk(GeneratedExport::DISK);

        abort_unless($disk->exists($export->file_path), 404);

        return $disk->download($export->file_path, basename($export->file_path));
    }
}
