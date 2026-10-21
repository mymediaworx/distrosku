<?php

namespace App\Filament\Resources\InventoryCountResource\RelationManagers;

use App\Inventory\CountService;
use App\Models\InventoryCountLine;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $show = fn () => CountService::showExpected($this->getOwnerRecord(), auth()->user());

        return $table->modifyQueryUsing(fn ($query) => $query->with('product', 'bin', 'lot'))->defaultSort('id')->paginated([50, 100, 'all'])
            ->columns([
                Tables\Columns\TextColumn::make('bin.code')->label('Bin')->placeholder('—'),
                Tables\Columns\TextColumn::make('product.sku')->label('SKU')->searchable(),
                Tables\Columns\TextColumn::make('product.name')->label('Item')->limit(30),
                Tables\Columns\TextColumn::make('lot.lot_number')->label('Lot')->placeholder(fn (InventoryCountLine $r) => $r->product->track_lots ? 'unlotted' : ''),
                Tables\Columns\TextColumn::make('expected')->numeric(2)->visible($show),
                Tables\Columns\TextColumn::make('counted')->numeric(2)->placeholder('not counted'),
                Tables\Columns\TextColumn::make('var')->label('Variance')->state(fn (InventoryCountLine $r) => (float) $r->variance())->visible($show)
                    ->color(fn ($state) => $state < 0 ? 'danger' : ($state > 0 ? 'success' : null)),
                Tables\Columns\TextColumn::make('val')->label('Value $')->state(fn (InventoryCountLine $r) => number_format((float) $r->varianceValue(), 2))->visible($show),
            ])
            ->filters([Tables\Filters\Filter::make('variances')->label('Variances only')->visible($show)
                ->query(fn ($query) => $query->whereNotNull('counted')->whereColumn('counted', '!=', 'expected'))])
            ->actions([
                Tables\Actions\Action::make('set')->label('Set count')->icon('heroicon-o-pencil')
                    ->visible(fn () => $this->getOwnerRecord()->status === 'counting')
                    ->form([TextInput::make('qty')->label('Counted (stock units)')->numeric()->minValue(0)->required()])
                    ->action(function (InventoryCountLine $r, array $data) {
                        try {
                            app(CountService::class)->setCounted($r, $data['qty'], auth()->user());
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ]);
    }
}
