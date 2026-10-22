<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\PriceOverrideLogResource\Pages;
use App\Models\PriceOverrideLog;
use App\Pricing\PricingEngine;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Read-only log of manual line-price overrides (admin SO, POS). */
class PriceOverrideLogResource extends Resource
{
    use RoleGated;

    protected static string $module = 'price_overrides';

    protected static ?string $model = PriceOverrideLog::class;

    protected static ?string $modelLabel = 'price override';
    protected static ?string $pluralModelLabel = 'price overrides'; // page title matches the sidebar (UI audit #6)

    protected static ?string $navigationGroup = 'Pricing';

    protected static ?string $navigationLabel = 'Price overrides';

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            Tables\Columns\TextColumn::make('created_at')->dateTime()->label('When'),
            Tables\Columns\TextColumn::make('salesOrder.number')->label('Order')->searchable(),
            Tables\Columns\TextColumn::make('product.sku')->label('SKU')->searchable(),
            Tables\Columns\TextColumn::make('quoted_price')->money('USD')->label('Quoted'),
            Tables\Columns\TextColumn::make('quoted_source')->label('Quote source')->formatStateUsing(fn ($state) => PricingEngine::sourceLabel($state)),
            Tables\Columns\TextColumn::make('old_price')->money('USD')->label('Was')->placeholder('-'),
            Tables\Columns\TextColumn::make('new_price')->money('USD')->label('Set to'),
            Tables\Columns\TextColumn::make('user.name')->label('By')->placeholder('system'),
            Tables\Columns\TextColumn::make('channel'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPriceOverrideLogs::route('/')];
    }
}
