<?php

namespace App\Filament\Resources\CkpnWorkpapers\Pages;

use App\Actions\Ckpn\CreateCkpnWorkpaperAction;
use App\Filament\Resources\CkpnWorkpapers\CkpnWorkpaperResource;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCkpnWorkpaper extends CreateRecord
{
    protected static string $resource = CkpnWorkpaperResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return app(CreateCkpnWorkpaperAction::class)->handle($data, $user);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('CKPN Workpaper created. Generation is being processed in the background.');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
