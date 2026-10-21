<?php

namespace App\Filament\Resources\CustomerPriceOverrideResource\Pages;

use App\Filament\Resources\CustomerPriceOverrideResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCustomerPriceOverride extends EditRecord
{
    protected static string $resource = CustomerPriceOverrideResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
