<?php

namespace App\Filament\Resources;

use App\Exceptions\WorkflowException;
use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\LotReviewItemResource\Pages;
use App\Models\Lot;
use App\Models\LotReviewItem;
use App\Warehouse\LotService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 0.20.15 (#36): lot-tracked sales that left without an assignable lot (no lot stock, several lots and none chosen,
 * chosen lot short or unavailable). The sale is never blocked; it lands here until someone picks the lot.
 */
class LotReviewItemResource extends Resource
{
    use RoleGated;

    protected static string $module = 'lots';

    protected static ?string $model = LotReviewItem::class;

    protected static ?string $modelLabel = 'lot review item';

    protected static ?string $navigationLabel = 'Lots: needs review';

    protected static ?string $pluralModelLabel = 'Lots: needs review';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?int $navigationSort = 86;

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
        $n = LotReviewItem::where('status', 'open')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('salesOrder.customer', 'product', 'warehouse', 'lot', 'resolver'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Sold')->dateTime('Y-m-d H:i')->sortable(),
                Tables\Columns\TextColumn::make('salesOrder.number')->label('Order')->searchable(),
                Tables\Columns\TextColumn::make('salesOrder.customer.name')->label('Customer')->limit(25),
                Tables\Columns\TextColumn::make('product.sku')->label('SKU')->searchable(),
                Tables\Columns\TextColumn::make('qty')->numeric(2),
                Tables\Columns\TextColumn::make('reason')->formatStateUsing(fn ($state) => LotReviewItem::REASONS[$state] ?? $state)->wrap(),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => $state === 'open' ? 'danger' : 'success'),
                Tables\Columns\TextColumn::make('lot.lot_number')->label('Lot')->placeholder('—'),
                Tables\Columns\TextColumn::make('resolver.name')->label('Resolved by')->toggleable(),
                Tables\Columns\TextColumn::make('note')->limit(40)->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['open' => 'Open', 'resolved' => 'Resolved'])->default('open'),
            ])
            ->actions([
                Tables\Actions\Action::make('resolve')->label('Choose lot')->icon('heroicon-o-check')
                    ->visible(fn (LotReviewItem $r) => $r->status === 'open' && auth()->user()?->canModule('lots'))
                    ->form(fn (LotReviewItem $r) => [
                        Forms\Components\Select::make('lot_id')->label('Lot sold')->searchable()
                            ->options(Lot::where('product_id', $r->product_id)->orderByDesc('id')->limit(300)->get()
                                ->mapWithKeys(fn (Lot $l) => [$l->id => $l->lot_number.($l->expires_on ? ' · exp '.$l->expires_on->toDateString() : '')])->all())
                            ->helperText('Leave empty only for stock received before lot tracking; then give a note.'),
                        Forms\Components\Textarea::make('note')->rows(2)->maxLength(1000),
                    ])
                    ->action(function (LotReviewItem $r, array $data) {
                        try {
                            app(LotService::class)->resolveReview($r, ! empty($data['lot_id']) ? Lot::find($data['lot_id']) : null, auth()->id(), $data['note'] ?? null);
                        } catch (WorkflowException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }
                        Notification::make()->success()->title('Resolved')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListLotReviewItems::route('/')];
    }
}
