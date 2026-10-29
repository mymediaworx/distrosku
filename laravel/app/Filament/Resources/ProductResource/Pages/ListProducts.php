<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('catalogSearch')->label('Catalog search')->icon('heroicon-o-magnifying-glass')->color('gray')
                ->modalHeading('Catalog search')->modalWidth('4xl')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn () => view('filament.partials.catalog-search')),
            Actions\CreateAction::make(),
        ];
    }

    /** 0.20.19: the table's search box uses the same smart search as the picker (every word, any order, punctuation ignored, abbreviations). */
    protected function applyGlobalSearchToTableQuery(Builder $query): Builder
    {
        $search = trim((string) $this->getTableSearch());
        if ($search !== '') {
            \App\Support\ProductSearch::matching($query, $search);
        }

        return $query;
    }
}
