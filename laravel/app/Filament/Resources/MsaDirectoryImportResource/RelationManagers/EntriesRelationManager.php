<?php

namespace App\Filament\Resources\MsaDirectoryImportResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class EntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('brand_family')->columns([
            Tables\Columns\TextColumn::make('manufacturer')->searchable(),
            Tables\Columns\TextColumn::make('brand_family')->searchable(),
            Tables\Columns\TextColumn::make('product_type')->badge(),
            Tables\Columns\TextColumn::make('effective_from')->date(),
            Tables\Columns\TextColumn::make('effective_to')->date()->placeholder('open'),
        ]);
    }
}
