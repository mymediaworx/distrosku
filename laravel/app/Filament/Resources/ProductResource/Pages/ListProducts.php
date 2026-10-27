<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

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
}
