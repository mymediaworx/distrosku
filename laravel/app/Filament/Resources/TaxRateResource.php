<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\TaxRateResource\Pages;
use App\Models\TaxRate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TaxRateResource extends Resource
{
    use RoleGated;

    protected static string $module = 'tax_rates';

    protected static ?string $navigationGroup = 'Tax Filings';

    protected static ?int $navigationSort = 10;

    protected static ?string $model = TaxRate::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('jurisdiction')
                    ->required(),
                Forms\Components\TextInput::make('tax_type')
                    ->required(),
                Forms\Components\TextInput::make('rate')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('basis')
                    ->required(),
                Forms\Components\TextInput::make('increment_oz')
                    ->numeric(),
                Forms\Components\DatePicker::make('effective_from')
                    ->required(),
                Forms\Components\DatePicker::make('effective_to'),
                Forms\Components\Toggle::make('needs_verification')
                    ->required(),
                Forms\Components\TextInput::make('source'),
                Forms\Components\Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('jurisdiction')
                    ->searchable(),
                Tables\Columns\TextColumn::make('tax_type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('rate')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('basis')
                    ->searchable(),
                Tables\Columns\TextColumn::make('increment_oz')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('effective_from')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('effective_to')
                    ->date()
                    ->sortable(),
                Tables\Columns\IconColumn::make('needs_verification')
                    ->boolean(),
                Tables\Columns\TextColumn::make('source')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
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
            'index' => Pages\ListTaxRates::route('/'),
            'create' => Pages\CreateTaxRate::route('/create'),
            'edit' => Pages\EditTaxRate::route('/{record}/edit'),
        ];
    }
}
