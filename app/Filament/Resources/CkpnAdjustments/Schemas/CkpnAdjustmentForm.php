<?php

namespace App\Filament\Resources\CkpnAdjustments\Schemas;

use App\Models\CkpnAdjustment;
use App\Models\CkpnWorkpaperItem;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CkpnAdjustmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Adjustment')
                    ->schema([
                        Select::make('ckpn_workpaper_id')
                            ->label('Workpaper')
                            ->relationship('ckpnWorkpaper', 'period')
                            ->searchable()
                            ->preload(),
                        Select::make('ckpn_workpaper_item_id')
                            ->label('Workpaper item')
                            ->options(fn (): array => CkpnWorkpaperItem::query()
                                ->orderBy('id')
                                ->get()
                                ->mapWithKeys(fn (CkpnWorkpaperItem $item): array => [
                                    $item->id => "{$item->source_label} - {$item->branch_code} - {$item->loan_account_number} - {$item->customer_name}",
                                ])
                                ->all())
                            ->searchable()
                            ->required(),
                        TextInput::make('adjustment_type')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('original_rate')
                            ->numeric()
                            ->step('0.0001'),
                        TextInput::make('adjusted_rate')
                            ->numeric()
                            ->step('0.0001'),
                        TextInput::make('original_amount')
                            ->numeric()
                            ->step('0.01'),
                        TextInput::make('adjusted_amount')
                            ->numeric()
                            ->step('0.01'),
                        Select::make('status')
                            ->options(CkpnAdjustment::statusOptions())
                            ->disabled()
                            ->dehydrated(false),
                        Textarea::make('reason')
                            ->required()
                            ->columnSpanFull()
                            ->maxLength(65535),
                    ])
                    ->columns(2),
            ]);
    }
}
