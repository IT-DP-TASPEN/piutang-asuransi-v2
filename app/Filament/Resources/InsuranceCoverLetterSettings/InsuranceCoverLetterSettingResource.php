<?php

namespace App\Filament\Resources\InsuranceCoverLetterSettings;

use App\Filament\Resources\InsuranceCoverLetterSettings\Pages\EditInsuranceCoverLetterSetting;
use App\Filament\Resources\InsuranceCoverLetterSettings\Pages\ListInsuranceCoverLetterSettings;
use App\Models\InsuranceCoverLetterSetting;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InsuranceCoverLetterSettingResource extends Resource
{
    protected static ?string $model = InsuranceCoverLetterSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNumberedList;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Cover letter numbering';

    protected static ?string $modelLabel = 'Cover letter setting';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cover letter numbering')->schema([
                TextInput::make('sequence_base')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->helperText('Future letters use sequence base + cover letter ID. Existing numbers never change.'),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sequence_base')->label('Sequence base'),
                TextColumn::make('updated_at')->dateTime(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInsuranceCoverLetterSettings::route('/'),
            'edit' => EditInsuranceCoverLetterSetting::route('/{record}/edit'),
        ];
    }
}
