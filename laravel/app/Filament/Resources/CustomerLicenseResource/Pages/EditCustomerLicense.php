<?php

namespace App\Filament\Resources\CustomerLicenseResource\Pages;

use App\Filament\Resources\CustomerLicenseResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCustomerLicense extends EditRecord
{
    protected static string $resource = CustomerLicenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
