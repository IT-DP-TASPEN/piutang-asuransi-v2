<?php

namespace App\Filament\Resources\ClaimStatusChangeRequests\Pages;

use App\Actions\ClaimStatusChangeRequest\PrepareClaimStatusChangeRequestDataAction;
use App\Filament\Resources\ClaimStatusChangeRequests\ClaimStatusChangeRequestResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateClaimStatusChangeRequest extends CreateRecord
{
    protected static string $resource = ClaimStatusChangeRequestResource::class;

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

        return app(PrepareClaimStatusChangeRequestDataAction::class)->handle($data, $user);
    }
}
