<?php

namespace App\Filament\Resources\CustomerLicenseResource\Pages;

use App\Filament\Resources\CustomerLicenseResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateCustomerLicense extends CreateRecord
{
    protected static string $resource = CustomerLicenseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (blank($data['site_id'] ?? null) && blank($data['number'] ?? null)) {
            throw ValidationException::withMessages([
                'number' => 'Enter a Site ID and/or License #.',
            ]);
        }

        return $data;
    }
}
