<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Models\Vendor;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/** 0.20.17: the vendor item numbers linked to this product (the same vendor_items rows the Vendor screen edits). */
class VendorItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'vendorItems';

    protected static ?string $title = 'Vendor item numbers';

    protected static ?string $icon = 'heroicon-o-list-bullet';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->canModule('vendors', 'view');
    }

    public function isReadOnly(): bool
    {
        return ! auth()->user()?->isAdmin();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('vendor_id')->label('Vendor')->required()->searchable()
                ->options(fn () => Vendor::query()->orderBy('name')->pluck('name', 'id')->all()),
            Forms\Components\TextInput::make('vendor_item_no')->label('Vendor item #')->required()->maxLength(60)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Forms\Get $get) => $rule->where('vendor_id', $get('vendor_id'))),
            Forms\Components\TextInput::make('last_cost')->numeric()->minValue(0),
            Forms\Components\DatePicker::make('last_cost_on'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('vendor'))
            ->defaultSort('vendor_item_no')
            ->columns([
                Tables\Columns\TextColumn::make('vendor.name')->label('Vendor')->searchable(),
                Tables\Columns\TextColumn::make('vendor_item_no')->label('Vendor item #')->searchable()->sortable()->copyable(),
                Tables\Columns\TextColumn::make('last_cost')->money('USD')->sortable(),
                Tables\Columns\TextColumn::make('last_cost_on')->date()->toggleable(),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->bulkActions([]);
    }
}
