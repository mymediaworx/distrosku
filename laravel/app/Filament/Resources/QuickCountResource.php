<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\QuickCountResource\Pages;
use App\Inventory\QuickCountService;
use App\Models\QuickCount;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** 0.20.20: counts typed on the Quick Tools page. Warehouse can see them; an admin applies (posts the difference) or dismisses. */
class QuickCountResource extends Resource
{
    use RoleGated;

    protected static string $module = 'quick_counts';

    protected static ?string $model = QuickCount::class;

    protected static ?string $navigationGroup = 'Warehouse';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Quick counts';

    protected static ?int $navigationSort = 9;

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
            Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime('m/d/Y g:i A')->sortable(),
            Tables\Columns\TextColumn::make('product.sku')->label('SKU')->searchable(),
            Tables\Columns\TextColumn::make('product.name')->label('Product')->limit(40),
            Tables\Columns\TextColumn::make('warehouse.code')->label('WH'),
            Tables\Columns\TextColumn::make('counted_qty')->label('Counted')->numeric(decimalPlaces: 0),
            Tables\Columns\TextColumn::make('counted_by')->label('Counted by'),
            Tables\Columns\TextColumn::make('note')->limit(30),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => ['pending' => 'warning', 'applied' => 'success', 'dismissed' => 'gray'][$state] ?? 'gray'),
            Tables\Columns\TextColumn::make('applied_delta')->label('Change posted')->numeric(decimalPlaces: 0)->placeholder('—'),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options(['pending' => 'Pending', 'applied' => 'Applied', 'dismissed' => 'Dismissed'])->default('pending'),
        ])->actions([
            Tables\Actions\Action::make('apply')->label('Apply')->icon('heroicon-o-check')->color('success')->requiresConfirmation()
                ->modalDescription('Posts the difference between this count and the current on-hand as a stock adjustment.')
                ->visible(fn (QuickCount $r) => $r->status === 'pending' && auth()->user()?->canModule('quick_counts', 'manage'))
                ->action(function (QuickCount $r) {
                    try {
                        app(QuickCountService::class)->apply($r, auth()->user());
                        Notification::make()->success()->title('Count applied')->send();
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            Tables\Actions\Action::make('dismiss')->label('Dismiss')->icon('heroicon-o-x-mark')->color('gray')->requiresConfirmation()
                ->visible(fn (QuickCount $r) => $r->status === 'pending' && auth()->user()?->canModule('quick_counts', 'manage'))
                ->action(fn (QuickCount $r) => app(QuickCountService::class)->dismiss($r, auth()->user())),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListQuickCounts::route('/')];
    }
}
