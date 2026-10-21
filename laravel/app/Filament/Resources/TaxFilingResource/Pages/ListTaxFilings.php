<?php

namespace App\Filament\Resources\TaxFilingResource\Pages;

use App\Filament\Resources\TaxFilingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTaxFilings extends ListRecords
{
    protected static string $resource = TaxFilingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
