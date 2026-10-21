<?php

namespace App\Filament\Resources\CustomerLicenseResource\Pages;

use App\Filament\Resources\CustomerLicenseResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCustomerLicenses extends ListRecords
{
    protected static string $resource = CustomerLicenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
