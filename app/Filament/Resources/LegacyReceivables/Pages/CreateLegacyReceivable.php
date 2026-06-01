<?php

namespace App\Filament\Resources\LegacyReceivables\Pages;

use App\Actions\LegacyReceivable\PrepareLegacyReceivableDataAction;
use App\Filament\Resources\LegacyReceivables\LegacyReceivableResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateLegacyReceivable extends CreateRecord
{
    protected static string $resource = LegacyReceivableResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        return app(PrepareLegacyReceivableDataAction::class)->handle(
            $data,
            $user instanceof User ? $user : null,
        );
    }
}
