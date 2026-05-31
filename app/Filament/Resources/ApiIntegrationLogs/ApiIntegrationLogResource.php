<?php

namespace App\Filament\Resources\ApiIntegrationLogs;

use App\Filament\Resources\ApiIntegrationLogs\Pages\ListApiIntegrationLogs;
use App\Filament\Resources\ApiIntegrationLogs\Pages\ViewApiIntegrationLog;
use App\Filament\Resources\ApiIntegrationLogs\Schemas\ApiIntegrationLogForm;
use App\Filament\Resources\ApiIntegrationLogs\Schemas\ApiIntegrationLogInfolist;
use App\Filament\Resources\ApiIntegrationLogs\Tables\ApiIntegrationLogsTable;
use App\Models\ApiIntegrationLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ApiIntegrationLogResource extends Resource
{
    protected static ?string $model = ApiIntegrationLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Audit';

    protected static ?int $navigationSort = 90;

    protected static ?string $modelLabel = 'API integration log';

    protected static ?string $pluralModelLabel = 'API integration logs';

    protected static ?string $recordTitleAttribute = 'endpoint';

    public static function form(Schema $schema): Schema
    {
        return ApiIntegrationLogForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ApiIntegrationLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ApiIntegrationLogsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApiIntegrationLogs::route('/'),
            'view' => ViewApiIntegrationLog::route('/{record}'),
        ];
    }
}
