<?php

namespace App\Filament\Resources\CustomerPriceOverrideResource\Pages;

use App\Filament\Resources\CustomerPriceOverrideResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCustomerPriceOverrides extends ListRecords
{
    protected static string $resource = CustomerPriceOverrideResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
