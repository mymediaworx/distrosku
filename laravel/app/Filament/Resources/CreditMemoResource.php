<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\CreditMemoResource\Pages;
use App\Models\CreditMemo;
use App\Services\CreditMemoService;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Credit memos are created from an invoiced sales order ("Return / credit memo" action) and never edited after posting. */
class CreditMemoResource extends Resource
{
    use RoleGated;

    protected static string $module = 'credit_memos';

    protected static ?string $model = CreditMemo::class;

    protected static ?string $navigationGroup = 'Sales';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

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
        return $record->status === 'draft' && static::allowed('manage');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\TextEntry::make('number'),
            Infolists\Components\TextEntry::make('salesOrder.number')->label('Invoice'),
            Infolists\Components\TextEntry::make('customer.name'),
            Infolists\Components\TextEntry::make('memo_date')->date(),
            Infolists\Components\TextEntry::make('status')->badge(),
            Infolists\Components\TextEntry::make('total')->money('USD'),
            Infolists\Components\TextEntry::make('reason'),
            Infolists\Components\RepeatableEntry::make('lines')->schema([
                Infolists\Components\TextEntry::make('product.sku'),
                Infolists\Components\TextEntry::make('qty'),
                Infolists\Components\TextEntry::make('line_total')->money('USD'),
                Infolists\Components\TextEntry::make('disposition')->badge(),
            ])->columns(4)->columnSpanFull(),
        ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('number')->searchable(),
                Tables\Columns\TextColumn::make('salesOrder.number')->label('Invoice')->searchable(),
                Tables\Columns\TextColumn::make('customer.name')->searchable(),
                Tables\Columns\TextColumn::make('memo_date')->date()->sortable(),
                Tables\Columns\TextColumn::make('total')->money('USD'),
                Tables\Columns\TextColumn::make('status')->badge(),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->options(['draft' => 'Draft', 'posted' => 'Posted'])])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('post')->label('Post')->icon('heroicon-o-check')->requiresConfirmation()
                    ->visible(fn (CreditMemo $r) => $r->status === 'draft' && static::allowed('manage'))
                    ->action(function (CreditMemo $r) {
                        try {
                            app(CreditMemoService::class)->post($r);
                            Notification::make()->success()->title("{$r->number} posted")->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Not posted')->body($e->getMessage())->persistent()->send();
                        }
                    }),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [\App\Filament\RelationManagers\HistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCreditMemos::route('/'), 'view' => Pages\ViewCreditMemo::route('/{record}')];
    }
}
