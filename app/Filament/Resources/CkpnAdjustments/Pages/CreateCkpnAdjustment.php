<?php

namespace App\Filament\Resources\CkpnAdjustments\Pages;

use App\Actions\CkpnAdjustment\PrepareCkpnAdjustmentDataAction;
use App\Filament\Resources\CkpnAdjustments\CkpnAdjustmentResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateCkpnAdjustment extends CreateRecord
{
    protected static string $resource = CkpnAdjustmentResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return $data;
        }

        return app(PrepareCkpnAdjustmentDataAction::class)->handle($data, $user);
    }
}
