<?php

namespace App\Filament\Resources\CkpnCalculationRules;

use App\Filament\Resources\CkpnCalculationRules\Pages\CreateCkpnCalculationRule;
use App\Filament\Resources\CkpnCalculationRules\Pages\EditCkpnCalculationRule;
use App\Filament\Resources\CkpnCalculationRules\Pages\ListCkpnCalculationRules;
use App\Filament\Resources\CkpnCalculationRules\Schemas\CkpnCalculationRuleForm;
use App\Filament\Resources\CkpnCalculationRules\Tables\CkpnCalculationRulesTable;
use App\Models\CkpnCalculationRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CkpnCalculationRuleResource extends Resource
{
    protected static ?string $model = CkpnCalculationRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 50;

    protected static ?string $modelLabel = 'CKPN calculation rule';

    protected static ?string $pluralModelLabel = 'CKPN calculation rules';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CkpnCalculationRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CkpnCalculationRulesTable::configure($table);
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
            'index' => ListCkpnCalculationRules::route('/'),
            'create' => CreateCkpnCalculationRule::route('/create'),
            'edit' => EditCkpnCalculationRule::route('/{record}/edit'),
        ];
    }
}
