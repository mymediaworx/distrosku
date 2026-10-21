<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\WarehouseEventResource\Pages;
use App\Models\WarehouseEvent;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Read-only log of approved short picks and over-receipts. */
class WarehouseEventResource extends Resource
{
    use RoleGated;

    protected static string $module = 'warehouse_events';

    protected static ?string $model = WarehouseEvent::class;

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Short picks & over-receipts';

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?int $navigationSort = 90;

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
        return $table->defaultSort('created_at', 'desc')->modifyQueryUsing(fn (Builder $query) => $query->with('user', 'approver', 'product', 'purchaseOrder', 'salesOrder'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('type')->badge()->formatStateUsing(fn ($s) => ['short_pick' => 'Short pick', 'over_receipt' => 'Over-receipt', 'count_auto_approve' => 'Count auto-approved'][$s] ?? $s)
                    ->color(fn ($s) => $s === 'short_pick' ? 'warning' : 'info'),
                Tables\Columns\TextColumn::make('product.sku')->label('SKU')->searchable(),
                Tables\Columns\TextColumn::make('qty')->numeric(2),
                Tables\Columns\TextColumn::make('ref')->label('Document')->state(fn (WarehouseEvent $r) => $r->salesOrder?->number ?? $r->purchaseOrder?->number),
                Tables\Columns\TextColumn::make('reason')->limit(40)->wrap(),
                Tables\Columns\TextColumn::make('user.name')->label('By'),
                Tables\Columns\TextColumn::make('approver.name')->label('Approved by'),
                Tables\Columns\TextColumn::make('details')->formatStateUsing(fn ($s, WarehouseEvent $r) => collect($r->details ?? [])->map(fn ($v, $k) => "$k: $v")->implode(', '))->toggleable(),
            ])
            ->filters([Tables\Filters\SelectFilter::make('type')->options(['short_pick' => 'Short pick', 'over_receipt' => 'Over-receipt', 'count_auto_approve' => 'Count auto-approved'])]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListWarehouseEvents::route('/')];
    }
}
