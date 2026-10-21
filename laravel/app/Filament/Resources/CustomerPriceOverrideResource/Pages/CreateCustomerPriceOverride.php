<?php

namespace App\Filament\Resources\CustomerPriceOverrideResource\Pages;

use App\Filament\Resources\CustomerPriceOverrideResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomerPriceOverride extends CreateRecord
{
    protected static string $resource = CustomerPriceOverrideResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['created_by' => auth()->id()];
    }
}
