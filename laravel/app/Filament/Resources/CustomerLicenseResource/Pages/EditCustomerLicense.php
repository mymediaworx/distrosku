<?php

namespace App\Filament\Resources\CustomerLicenseResource\Pages;

use App\Filament\Resources\CustomerLicenseResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditCustomerLicense extends EditRecord
{
    protected static string $resource = CustomerLicenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (blank($data['site_id'] ?? null) && blank($data['number'] ?? null)) {
            throw ValidationException::withMessages([
                'number' => 'Enter a Site ID and/or License #.',
            ]);
        }

        return $data;
    }
}
