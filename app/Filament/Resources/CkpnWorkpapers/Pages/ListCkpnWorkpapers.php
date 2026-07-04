<?php

namespace App\Filament\Resources\CkpnWorkpapers\Pages;

use App\Actions\Ckpn\CreateAllBranchCkpnWorkpapersAction;
use App\Filament\Resources\CkpnWorkpapers\CkpnWorkpaperResource;
use App\Models\CkpnWorkpaper;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;

class ListCkpnWorkpapers extends ListRecords
{
    protected static string $resource = CkpnWorkpaperResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->createAllBranchWorkpapersAction(),
            CreateAction::make(),
        ];
    }

    private function createAllBranchWorkpapersAction(): Action
    {
        return Action::make('createAllBranchWorkpapers')
            ->label('Create All Branch Workpapers')
            ->form([
                DatePicker::make('period')
                    ->label('Tanggal Cutoff')
                    ->required(),
            ])
            ->visible(fn (): bool => auth()->user()?->can('create', CkpnWorkpaper::class) ?? false)
            ->action(function (array $data): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    abort(403);
                }

                try {
                    $workpapers = app(CreateAllBranchCkpnWorkpapersAction::class)->handle($data, $user);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title($this->validationMessage($exception))
                        ->send();

                    $this->halt(true);
                }

                Notification::make()
                    ->success()
                    ->title("{$workpapers->count()} CKPN Workpapers have been queued for generation.")
                    ->send();
            });
    }

    private function validationMessage(ValidationException $exception): string
    {
        return collect($exception->errors())
            ->flatten()
            ->first() ?: $exception->getMessage();
    }
}
