<?php

namespace App\Filament\Resources\InsuranceReceivables\Pages;

use App\Actions\InsuranceReceivable\PrepareInsuranceReceivableDraftAction;
use App\Filament\Resources\InsuranceReceivables\InsuranceReceivableResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateInsuranceReceivable extends CreateRecord
{
    protected static string $resource = InsuranceReceivableResource::class;

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

        return app(PrepareInsuranceReceivableDraftAction::class)->handle($data, $user);
    }
}
