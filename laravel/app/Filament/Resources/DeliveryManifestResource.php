<?php

namespace App\Filament\Resources;

use App\Delivery\RoutePlanner;
use App\Delivery\SignatureService;
use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\DeliveryManifestResource\Pages;
use App\Models\DeliveryManifest;
use App\Models\SalesOrder;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DeliveryManifestResource extends Resource
{
    use RoleGated;

    protected static string $module = 'delivery_manifests';

    protected static ?string $model = DeliveryManifest::class;

    protected static ?string $navigationGroup = 'Sales';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('number')->disabled()->dehydrated(false)->placeholder('Assigned on save'),
                Forms\Components\DatePicker::make('delivery_date')
                    ->required(),
                Forms\Components\Select::make('warehouse_id')->relationship('warehouse', 'name')->searchable()->preload()->required(),
                Forms\Components\Select::make('driver_id')->label('Driver')->options(fn () => User::where('role', 'driver')->pluck('name', 'id')),
                Forms\Components\Select::make('status')->options(['open' => 'Open', 'out' => 'Out for delivery', 'closed' => 'Closed'])->default('open')->required(),
                Forms\Components\TextInput::make('route_name')->maxLength(60)->helperText('Optional: plan only ship-tos on this route'),
                Forms\Components\Repeater::make('stops')->relationship()->orderColumn('sequence')->columnSpanFull()->columns(['default' => 1, 'md' => 3])->schema([
                    Forms\Components\Select::make('sales_order_id')->label('Order')->required()->searchable()
                        ->options(fn () => SalesOrder::whereIn('status', ['confirmed', 'picked', 'delivered'])->with('customer')->get()->mapWithKeys(fn ($o) => [$o->id => "{$o->number} - {$o->customer->name}"])),
                    Forms\Components\Select::make('status')->options(['pending' => 'Pending', 'delivered' => 'Delivered', 'partial' => 'Partial', 'refused' => 'Refused'])->default('pending')
                        ->disabled(fn ($record) => $record && $record->status !== 'pending')
                        ->rule(fn (Forms\Get $get) => function ($attr, $value, $fail) use ($get) {
                            // Office edits can't bypass the signature: regulated orders are completed from the driver screen.
                            if (in_array($value, ['delivered', 'partial'], true) && ($so = SalesOrder::find($get('sales_order_id')))
                                && app(SignatureService::class)->required($so) && ! $so->signatures()->exists()) {
                                $fail("{$so->number} has regulated products: complete it on the driver screen with a signature.");
                            }
                        }),
                    Forms\Components\TextInput::make('received_by'),
                    Forms\Components\Textarea::make('notes')->rows(1),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->searchable(),
                Tables\Columns\TextColumn::make('delivery_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('warehouse.name')
                    ->sortable(),
                Tables\Columns\TextColumn::make('driver.name')
                    ->sortable(),
                Tables\Columns\TextColumn::make('route_name')->toggleable(),
                Tables\Columns\TextColumn::make('stops_count')->counts('stops')->label('Stops'),
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
                Tables\Actions\Action::make('planRoute')->label('Plan route')->icon('heroicon-o-map')
                    ->visible(fn (DeliveryManifest $r) => $r->status !== 'closed' && static::allowed('manage'))
                    ->form([Forms\Components\Toggle::make('ignore_days')->label('Include orders regardless of delivery day')])
                    ->action(function (DeliveryManifest $r, array $data) {
                        $n = app(RoutePlanner::class)->plan($r, null, (bool) ($data['ignore_days'] ?? false));
                        Notification::make()->success()->title("Added {$n} stop(s) to {$r->number}.")->send();
                    }),
                Tables\Actions\Action::make('driverView')->label('Driver view')->icon('heroicon-o-device-phone-mobile')
                    ->url(fn (DeliveryManifest $r) => route('driver.today', ['date' => $r->delivery_date->toDateString()])),
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
            \App\Filament\RelationManagers\HistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeliveryManifests::route('/'),
            'create' => Pages\CreateDeliveryManifest::route('/create'),
            'edit' => Pages\EditDeliveryManifest::route('/{record}/edit'),
        ];
    }
}
