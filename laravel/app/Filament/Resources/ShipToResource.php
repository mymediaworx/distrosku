<?php

namespace App\Filament\Resources;

use App\Delivery\DeliveryDays;
use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\ShipToResource\Pages;
use App\Models\ShipTo;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ShipToResource extends Resource
{
    use RoleGated;

    protected static string $module = 'ship_tos';

    protected static ?string $model = ShipTo::class;

    protected static ?string $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('customer_id')->relationship('customer', 'name')->searchable()->preload()->required(),
                ...static::addressFields(),
            ]);
    }

    /** 0.19.3: shared by this resource and the Customer > Ship-to addresses tab. */
    public static function addressFields(): array
    {
        return [
                Forms\Components\TextInput::make('name')
                    ->required(),
                Forms\Components\TextInput::make('address'),
                Forms\Components\TextInput::make('city'),
                Forms\Components\TextInput::make('county'),
                Forms\Components\TextInput::make('state')
                    ->required(),
                Forms\Components\TextInput::make('zip'),
                Forms\Components\Radio::make('fulfillment')->label('Fulfillment')->options(ShipTo::FULFILLMENT)->default('pickup')->inline()->required()
                    ->helperText('Counter pickup (default): orders are not put on delivery routes. Choose Delivery to use the delivery days and route below.')->columnSpanFull(),
                Forms\Components\CheckboxList::make('delivery_days')->options(DeliveryDays::DAYS)->columns(7)->gridDirection('row')
                    ->formatStateUsing(fn ($state) => DeliveryDays::parse($state))->dehydrateStateUsing(fn ($state) => DeliveryDays::toCsv($state))->helperText('Leave empty to use the customer\'s delivery days.')->columnSpanFull(),
                Forms\Components\TextInput::make('route_name')->maxLength(60),
                Forms\Components\TextInput::make('route_sequence')->numeric()->minValue(0)->helperText('Stop order within the route'),
                Forms\Components\TextInput::make('phone')->tel(),
                Forms\Components\Fieldset::make('MSA')->visible(fn () => (bool) auth()->user()?->isAdmin())->schema([
                    Forms\Components\TextInput::make('msa_shipping_number')->label('MSA shipping # (blank = ship-to id)')->maxLength(8),
                    Forms\Components\TextInput::make('msa_shipping_ext')->label('Shipping # ext')->maxLength(8),
                    Forms\Components\TextInput::make('msa_store_number')->label('Chain store #')->maxLength(8),
                    Forms\Components\TextInput::make('msa_tdlinx')->label('TDLinx #')->maxLength(7),
                    Forms\Components\Toggle::make('msa_cash_and_carry')->label('Cash and carry'),
                ])->columns(5),
                Forms\Components\Toggle::make('is_default')->label('Default ship-to')
                    ->helperText('Used first on new orders, POS and marketing. Only one per customer.'),
        ];
    }

    /** 0.19.3: ship-tos are managed on the customer page; this list stays reachable by URL but is not in the menu. */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('address')
                    ->searchable(),
                Tables\Columns\TextColumn::make('city')
                    ->searchable(),
                Tables\Columns\TextColumn::make('county')
                    ->searchable(),
                Tables\Columns\TextColumn::make('state')
                    ->searchable(),
                Tables\Columns\TextColumn::make('zip')
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_default')->label('Default')->boolean(),
                Tables\Columns\TextColumn::make('fulfillment')->badge()->formatStateUsing(fn ($state) => ShipTo::FULFILLMENT[$state] ?? $state),
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
                Tables\Actions\Action::make('customer')->label('Open customer')->icon('heroicon-o-user')
                    ->url(fn (ShipTo $r) => CustomerResource::getUrl('edit', ['record' => $r->customer_id])),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
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
            'index' => Pages\ListShipTos::route('/'),
            'create' => Pages\CreateShipTo::route('/create'),
            'edit' => Pages\EditShipTo::route('/{record}/edit'),
        ];
    }
}
