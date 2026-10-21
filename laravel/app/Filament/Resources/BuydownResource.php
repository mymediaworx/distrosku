<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\BuydownResource\Pages;
use App\Models\Buydown;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BuydownResource extends Resource
{
    use RoleGated;

    protected static string $module = 'buydowns';

    protected static ?string $model = Buydown::class;

    protected static ?string $navigationGroup = 'Catalog';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('kind')->options(['buydown' => 'Buydown', 'rebate' => 'Manufacturer rebate'])->default('buydown')->required(),
                Forms\Components\TextInput::make('manufacturer')
                    ->required(),
                Forms\Components\Select::make('vendor_id')->label('Claim from vendor')->relationship('vendor', 'name')->searchable()->preload()->nullable(),
                Forms\Components\TextInput::make('program_code')->maxLength(50),
                Forms\Components\Select::make('product_id')->relationship('product', 'name')->searchable()->preload(),
                Forms\Components\TextInput::make('description')
                    ->required(),
                Forms\Components\TextInput::make('amount_per_unit')
                    ->required()
                    ->numeric(),
                Forms\Components\DatePicker::make('starts_on')
                    ->required(),
                Forms\Components\DatePicker::make('ends_on'),
                Forms\Components\TextInput::make('max_units')
                    ->numeric(),
                Forms\Components\TextInput::make('units_sold')->numeric()->default(0)->disabled()->dehydrated(false)
                    ->helperText('Counted automatically when orders are invoiced.'),
                Forms\Components\Select::make('status')->options(['active' => 'Active', 'ended' => 'Ended'])->default('active')->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('kind')->badge(),
                Tables\Columns\TextColumn::make('manufacturer')
                    ->searchable(),
                Tables\Columns\TextColumn::make('product.name')
                    ->sortable(),
                Tables\Columns\TextColumn::make('description')
                    ->searchable(),
                Tables\Columns\TextColumn::make('amount_per_unit')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('starts_on')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('ends_on')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('max_units')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('units_sold')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
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
            'index' => Pages\ListBuydowns::route('/'),
            'create' => Pages\CreateBuydown::route('/create'),
            'edit' => Pages\EditBuydown::route('/{record}/edit'),
        ];
    }
}
