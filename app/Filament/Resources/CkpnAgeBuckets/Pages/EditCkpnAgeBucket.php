<?php

namespace App\Filament\Resources\CkpnAgeBuckets\Pages;

use App\Filament\Resources\CkpnAgeBuckets\CkpnAgeBucketResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCkpnAgeBucket extends EditRecord
{
    protected static string $resource = CkpnAgeBucketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
