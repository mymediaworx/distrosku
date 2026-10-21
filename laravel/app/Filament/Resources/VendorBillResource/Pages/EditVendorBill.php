<?php

namespace App\Filament\Resources\VendorBillResource\Pages;

use App\Accounting\ApService;
use App\Filament\Resources\VendorBillResource;
use Filament\Resources\Pages\EditRecord;

class EditVendorBill extends EditRecord
{
    protected static string $resource = VendorBillResource::class;

    protected function afterSave(): void
    {
        app(ApService::class)->recalc($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return VendorBillResource::getUrl('view', ['record' => $this->record]);
    }
}
