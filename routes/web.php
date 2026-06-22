<?php

use App\Http\Controllers\DownloadGeneratedExportController;
use App\Http\Controllers\DownloadInsuranceCoverLetterPdfController;
use App\Http\Controllers\DownloadInsuranceReceivableDocumentController;
use App\Http\Controllers\PreviewInsuranceCoverLetterController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

Route::get('/login', fn () => redirect('/admin/login'))->name('login');

Route::middleware('auth')->group(function (): void {
    Route::get('/insurance-receivable-documents/{document}/download', DownloadInsuranceReceivableDocumentController::class)
        ->name('insurance-receivable-documents.download');
    Route::get('/generated-exports/{export}/download', DownloadGeneratedExportController::class)
        ->name('generated-exports.download');
    Route::get('/insurance-cover-letters/{letter}/preview', PreviewInsuranceCoverLetterController::class)
        ->name('insurance-cover-letters.preview');
    Route::get('/insurance-cover-letters/{letter}/download', DownloadInsuranceCoverLetterPdfController::class)
        ->name('insurance-cover-letters.download');
});
