<?php

namespace App\Filament\Resources\InsuranceReceivables\Pages;

use App\Actions\InsuranceReceivable\CreateInsuranceReceivableAction;
use App\Filament\Resources\InsuranceReceivables\InsuranceReceivableResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Filament\Resources\Pages\CreateRecord;

class CreateInsuranceReceivable extends CreateRecord
{
    protected static string $resource = InsuranceReceivableResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return parent::handleRecordCreation($data);
        }

        return app(CreateInsuranceReceivableAction::class)->handle($data, $user);
    }
}
