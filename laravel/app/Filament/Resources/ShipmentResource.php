<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\ShipmentResource\Pages;
use App\Models\Carrier;
use App\Models\Package;
use App\Warehouse\ShippingService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Cartons with carrier + tracking (manual entry), label printing and tracking links. */
class ShipmentResource extends Resource
{
    use RoleGated;

    protected static string $module = 'shipping';

    protected static ?string $model = Package::class;

    protected static ?string $pluralModelLabel = 'shipments'; // page title matches the sidebar (UI audit #6)

    protected static ?string $navigationGroup = 'Delivery';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Shipments';

    protected static ?string $modelLabel = 'carton';

    protected static ?string $navigationIcon = 'heroicon-o-cube';

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

    public static function trackingAction(string $ns)
    {
        return $ns::make('tracking')->label('Carrier & tracking')->icon('heroicon-o-truck')
            ->visible(fn () => auth()->user()?->canModule('shipping', 'manage'))
            ->fillForm(fn (Package $r) => ['carrier_id' => $r->carrier_id, 'service' => $r->service, 'tracking_number' => $r->tracking_number])
            ->form([
                Forms\Components\Select::make('carrier_id')->label('Carrier')->options(fn () => Carrier::where('active', true)->orderBy('name')->pluck('name', 'id')),
                Forms\Components\TextInput::make('service')->maxLength(40)->placeholder('Ground'),
                Forms\Components\TextInput::make('tracking_number')->maxLength(60),
            ])
            ->action(function (Package $r, array $data) {
                try {
                    app(ShippingService::class)->assign($r, $data['carrier_id'] ?? null, $data['tracking_number'] ?? null, $data['service'] ?? null);
                    Notification::make()->success()->title('Saved')->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->modifyQueryUsing(fn ($query) => $query->with('carrier', 'pickList.salesOrder.customer', 'pickList.salesOrder.shipTo')->where('status', 'closed'))
            ->columns([
                Tables\Columns\TextColumn::make('pickList.salesOrder.number')->label('Order')->searchable(),
                Tables\Columns\TextColumn::make('pickList.salesOrder.customer.name')->label('Customer')->limit(25),
                Tables\Columns\TextColumn::make('pickList.salesOrder.shipTo.state')->label('ST'),
                Tables\Columns\TextColumn::make('seq')->label('Carton'),
                Tables\Columns\TextColumn::make('weight_lb')->label('lb'),
                Tables\Columns\TextColumn::make('carrier.name')->placeholder('—'),
                Tables\Columns\TextColumn::make('tracking_number')->searchable()->placeholder('—')
                    ->url(fn (Package $r) => $r->trackingUrl(), shouldOpenInNewTab: true)->color('primary'),
                Tables\Columns\TextColumn::make('shipped_at')->dateTime()->placeholder('—'),
            ])
            ->filters([Tables\Filters\TernaryFilter::make('tracking_number')->label('Has tracking')->nullable()])
            ->actions([
                self::trackingAction(Tables\Actions\Action::class),
                Tables\Actions\Action::make('label')->icon('heroicon-o-printer')->url(fn (Package $r) => route('wh.print.label', $r), shouldOpenInNewTab: true),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListShipments::route('/')];
    }
}
