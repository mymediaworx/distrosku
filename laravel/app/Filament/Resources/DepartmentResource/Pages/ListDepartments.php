<?php

namespace App\Filament\Resources\DepartmentResource\Pages;

use App\Filament\Resources\DepartmentResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDepartments extends ListRecords
{
    protected static string $resource = DepartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('catalogSearch')->label('Catalog search')->icon('heroicon-o-magnifying-glass')->color('gray')
                ->modalHeading('Catalog search')->modalWidth('4xl')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn () => view('filament.partials.catalog-search')),
            CreateAction::make(),
        ];
    }
}
