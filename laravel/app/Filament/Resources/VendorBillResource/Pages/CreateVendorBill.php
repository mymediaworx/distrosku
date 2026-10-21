<?php

namespace App\Filament\Resources\VendorBillResource\Pages;

use App\Accounting\ApService;
use App\Filament\Resources\VendorBillResource;
use App\Models\VendorBill;
use App\Services\Sequence;
use Filament\Resources\Pages\CreateRecord;

class CreateVendorBill extends CreateRecord
{
    protected static string $resource = VendorBillResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['number' => Sequence::next('BILL-', VendorBill::class)];
    }

    protected function afterCreate(): void
    {
        app(ApService::class)->recalc($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return VendorBillResource::getUrl('view', ['record' => $this->record]);
    }
}
