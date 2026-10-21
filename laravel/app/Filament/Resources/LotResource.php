<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\LotResource\Pages;
use App\Models\Lot;
use App\Models\LotMovement;
use App\Warehouse\LotService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Lots with expiry and on-hand stock. Expiry alerts are the "Expiring" filter; hold stops a lot being picked (recall/quarantine). */
class LotResource extends Resource
{
    use RoleGated;

    protected static string $module = 'lots';

    protected static ?string $model = Lot::class;

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?int $navigationSort = 85;

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

    public static function getNavigationBadge(): ?string
    {
        $n = Lot::whereNotNull('expires_on')->whereDate('expires_on', '<=', now()->addDays(LotService::alertDays()))
            ->whereHas('stocks', fn ($q) => $q->where('qty', '>', 0))->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        $days = LotService::alertDays();

        return $table->defaultSort('expires_on')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('product', 'stocks'))
            ->columns([
                Tables\Columns\TextColumn::make('product.sku')->label('SKU')->searchable(),
                Tables\Columns\TextColumn::make('product.name')->label('Item')->limit(30),
                Tables\Columns\TextColumn::make('lot_number')->label('Lot')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('expires_on')->date()->sortable()->badge()
                    ->color(fn (Lot $r) => ! $r->expires_on ? 'gray' : ($r->expires_on->isPast() ? 'danger' : ($r->expires_on->lte(now()->addDays($days)) ? 'warning' : 'success'))),
                Tables\Columns\TextColumn::make('on_hand')->label('On hand')->state(fn (Lot $r) => (float) $r->stocks->sum('qty'))->numeric(2),
                Tables\Columns\IconColumn::make('on_hold')->boolean()->label('Hold'),
                Tables\Columns\TextColumn::make('created_at')->label('First received')->date()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('in_stock')->label('In stock')->default()->query(fn (Builder $query) => $query->whereHas('stocks', fn ($s) => $s->where('qty', '>', 0))),
                Tables\Filters\Filter::make('expiring')->label("Expiring within {$days} days / expired")
                    ->query(fn (Builder $query) => $query->whereNotNull('expires_on')->whereDate('expires_on', '<=', now()->addDays($days))),
                Tables\Filters\TernaryFilter::make('on_hold'),
            ])
            ->actions([
                Tables\Actions\Action::make('recall')->label('Recall look-up')->icon('heroicon-o-magnifying-glass')
                    ->url(fn (Lot $r) => Pages\RecallLookup::getUrl(['lot' => $r->lot_number, 'product' => $r->product_id])),
                Tables\Actions\Action::make('hold')->label(fn (Lot $r) => $r->on_hold ? 'Release' : 'Hold')->icon('heroicon-o-hand-raised')
                    ->visible(fn () => auth()->user()?->isAdmin())->requiresConfirmation()
                    ->action(function (Lot $r) {
                        $r->update(['on_hold' => ! $r->on_hold]);
                        LotMovement::create(['lot_id' => $r->id, 'warehouse_id' => $r->stocks()->value('warehouse_id') ?? 1, 'qty' => 0, 'type' => 'adjust',
                            'user_id' => auth()->id(), 'note' => $r->on_hold ? 'Placed on hold' : 'Released from hold']);
                        Notification::make()->success()->title($r->on_hold ? "Lot {$r->lot_number} on hold" : "Lot {$r->lot_number} released")->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLots::route('/'),
            'recall' => Pages\RecallLookup::route('/recall'),
        ];
    }
}
