<?php

namespace App\Filament\Resources\VendorBillResource\Pages;

use App\Accounting\ApService;
use App\Filament\Resources\VendorBillResource;
use Filament\Resources\Pages\EditRecord;

class EditVendorBill extends EditRecord
{
    /** 0.20.5: full-width so the lines table has room. */
    public function getMaxContentWidth(): \Filament\Support\Enums\MaxWidth|string|null
    {
        return \Filament\Support\Enums\MaxWidth::Full;
    }

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
