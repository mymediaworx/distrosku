<?php

namespace App\Filament\Resources\VendorBillResource\Pages;

use App\Filament\Resources\VendorBillResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewVendorBill extends ViewRecord
{
    protected static string $resource = VendorBillResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            VendorBillResource::postAction(Actions\Action::class),
            VendorBillResource::voidAction(Actions\Action::class),
        ];
    }
}
