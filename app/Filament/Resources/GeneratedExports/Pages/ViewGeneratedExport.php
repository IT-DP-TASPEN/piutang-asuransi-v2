<?php

namespace App\Filament\Resources\GeneratedExports\Pages;

use App\Filament\Resources\GeneratedExports\GeneratedExportResource;
use App\Models\GeneratedExport;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewGeneratedExport extends ViewRecord
{
    protected static string $resource = GeneratedExportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')
                ->label('Download')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn (): bool => $this->getRecord()->status === GeneratedExport::STATUS_GENERATED
                    && filled($this->getRecord()->file_path)
                    && (auth()->user()?->can('view', $this->getRecord()) ?? false))
                ->url(fn (): string => route('generated-exports.download', $this->getRecord()))
                ->openUrlInNewTab(),
        ];
    }
}
