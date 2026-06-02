<?php

use App\Http\Controllers\DownloadInsuranceReceivableDocumentController;
use App\Http\Controllers\DownloadGeneratedExportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/insurance-receivable-documents/{document}/download', DownloadInsuranceReceivableDocumentController::class)
        ->name('insurance-receivable-documents.download');
    Route::get('/generated-exports/{export}/download', DownloadGeneratedExportController::class)
        ->name('generated-exports.download');
});
