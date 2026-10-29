<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Filament\Resources\ShipToResource;
use App\Models\ShipTo;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** 0.19.3: ship-to addresses on the customer page (same fields and permissions as the old Ship Tos screen). */
class ShipTosRelationManager extends RelationManager
{
    protected static string $relationship = 'shipTos';

    protected static ?string $title = 'Ship To';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->canModule('ship_tos');
    }

    private static function canManage(): bool
    {
        return (bool) auth()->user()?->canModule('ship_tos', 'manage');
    }

    protected function canCreate(): bool
    {
        return self::canManage();
    }

    protected function canEdit(Model $record): bool
    {
        return self::canManage();
    }

    protected function canDelete(Model $record): bool
    {
        return self::canManage();
    }

    public function isReadOnly(): bool
    {
        return ! self::canManage();
    }

    public function form(Form $form): Form
    {
        return $form->schema(ShipToResource::addressFields())->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table->recordTitleAttribute('name')
            ->defaultSort('is_default', 'desc')
            ->columns([
                Tables\Columns\IconColumn::make('is_default')->label('Default')->boolean(),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('fulfillment')->badge()->formatStateUsing(fn ($state) => ShipTo::FULFILLMENT[$state] ?? $state),
                Tables\Columns\TextColumn::make('address'),
                Tables\Columns\TextColumn::make('city'),
                Tables\Columns\TextColumn::make('county'),
                Tables\Columns\TextColumn::make('state'),
                Tables\Columns\TextColumn::make('zip'),
                Tables\Columns\TextColumn::make('route_name')->label('Route')->toggleable(),
                Tables\Columns\TextColumn::make('licenses_count')->counts('licenses')->label('Licenses'),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()->label('Add ship-to')])
            ->actions([
                Tables\Actions\Action::make('makeDefault')->label('Make default')->icon('heroicon-o-star')
                    ->visible(fn (ShipTo $r) => ! $r->is_default && self::canManage())
                    ->action(fn (ShipTo $r) => $r->update(['is_default' => true])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (ShipTo $record, Tables\Actions\DeleteAction $action) {
                        if ($record->inUse()) {
                            Notification::make()->danger()->title('This ship-to is used on orders and cannot be deleted.')->send();
                            $action->cancel();
                        }
                    }),
            ]);
    }
}
