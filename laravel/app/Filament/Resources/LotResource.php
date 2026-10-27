<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\LotResource\Pages;
use App\Models\Lot;
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
                Tables\Columns\TextColumn::make('coa')->label('COA')->badge()->state(fn (Lot $r) => $r->product?->hasCannabinoid() ? ($r->coaMissing() ? 'COA missing' : 'COA on file') : '')
                    ->color(fn (string $state) => $state === 'COA missing' ? 'danger' : 'success'),
                Tables\Columns\TextColumn::make('created_at')->label('First received')->date()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('in_stock')->label('In stock')->default()->query(fn (Builder $query) => $query->whereHas('stocks', fn ($s) => $s->where('qty', '>', 0))),
                Tables\Filters\Filter::make('expiring')->label("Expiring within {$days} days / expired")
                    ->query(fn (Builder $query) => $query->whereNotNull('expires_on')->whereDate('expires_on', '<=', now()->addDays($days))),
                Tables\Filters\TernaryFilter::make('on_hold'),
                Tables\Filters\Filter::make('coa_missing')->label('Hemp lots with COA missing')
                    ->query(fn (Builder $query) => $query->whereHas('product', fn ($p) => $p->where(fn ($w) => $w->whereIn('product_subtype', ['hemp', 'cbd'])->orWhere('hemp_cannabinoid', true)->orWhereNotNull('hemp_type')))
                        ->whereNotExists(fn ($c) => $c->from('product_coas')->whereColumn('product_coas.product_id', 'lots.product_id')
                            ->where(fn ($w) => $w->whereColumn('product_coas.lot_id', 'lots.id')->orWhereColumn('product_coas.batch_lot', 'lots.lot_number')))),
            ])
            ->actions([
                Tables\Actions\Action::make('trace')->label('Trace')->icon('heroicon-o-magnifying-glass')
                    ->url(fn (Lot $r) => Pages\Trace::getUrl(['q' => $r->lot_number])),
                Tables\Actions\Action::make('hold')->label(fn (Lot $r) => $r->on_hold ? 'Release' : 'Hold')->icon('heroicon-o-hand-raised')
                    ->visible(fn () => auth()->user()?->isAdmin())->requiresConfirmation()
                    ->action(function (Lot $r) {
                        app(LotService::class)->setHold($r, ! $r->on_hold);
                        Notification::make()->success()->title($r->on_hold ? "Lot {$r->lot_number} on hold" : "Lot {$r->lot_number} released")->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLots::route('/'),
            'recall' => Pages\RecallLookup::route('/recall'),
            'trace' => Pages\Trace::route('/trace'),
        ];
    }
}
