<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Filament\Resources\PurchaseOrderResource;
use App\Services\PurchaseOrderService;
use Filament\Resources\Pages\CreateRecord;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function afterSave(): void
    {
        app(PurchaseOrderService::class)->recalc($this->record);
    }

    protected function afterCreate(): void
    {
        app(PurchaseOrderService::class)->recalc($this->record);
    }
}
