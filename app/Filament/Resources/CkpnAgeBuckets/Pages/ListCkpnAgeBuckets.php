<?php

namespace App\Filament\Resources\CkpnAgeBuckets\Pages;

use App\Filament\Resources\CkpnAgeBuckets\CkpnAgeBucketResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCkpnAgeBuckets extends ListRecords
{
    protected static string $resource = CkpnAgeBucketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
