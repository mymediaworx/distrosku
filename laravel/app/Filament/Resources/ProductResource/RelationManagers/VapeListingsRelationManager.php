<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** 0.18: state vape directory listings for this SKU (used by rules with "must be on the state vape directory"). */
class VapeListingsRelationManager extends RelationManager
{
    protected static string $relationship = 'vapeListings';

    protected static ?string $title = 'State vape directories';

    protected static ?string $icon = 'heroicon-o-list-bullet';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->canModule('products') && $ownerRecord->isVapeForm();
    }

    public function isReadOnly(): bool
    {
        return ! auth()->user()?->canModule('products', 'manage');
    }

    public function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('state')->required()->length(2)->dehydrateStateUsing(fn ($state) => strtoupper((string) $state)),
            Forms\Components\TextInput::make('listing_ref')->label('Directory ID / reference')->maxLength(100),
            Forms\Components\DatePicker::make('listed_from')->required(),
            Forms\Components\DatePicker::make('listed_to')->label('Removed on (blank = still listed)')->afterOrEqual('listed_from'),
            Forms\Components\TextInput::make('source')->maxLength(250)->columnSpanFull(),
            Forms\Components\TextInput::make('note')->maxLength(250)->columnSpanFull(),
            Forms\Components\Hidden::make('manual')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->recordTitleAttribute('state')
            ->columns([
                Tables\Columns\TextColumn::make('state'),
                Tables\Columns\TextColumn::make('listing_ref')->label('Reference'),
                Tables\Columns\TextColumn::make('listed_from')->date(),
                Tables\Columns\TextColumn::make('listed_to')->date()->placeholder('Listed'),
                Tables\Columns\IconColumn::make('manual')->boolean()->label('Manual'),
                Tables\Columns\TextColumn::make('note')->limit(60)->wrap(),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()->label('Add listing')])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->bulkActions([]);
    }
}
