<?php

namespace App\Filament\Resources\PriceOverrideLogResource\Pages;

use App\Filament\Resources\PriceOverrideLogResource;
use Filament\Resources\Pages\ListRecords;

class ListPriceOverrideLogs extends ListRecords
{
    protected static string $resource = PriceOverrideLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
