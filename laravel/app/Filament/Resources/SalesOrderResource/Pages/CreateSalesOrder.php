<?php

namespace App\Filament\Resources\SalesOrderResource\Pages;

use App\Filament\Resources\SalesOrderResource;
use App\Services\SalesOrderService;
use Filament\Resources\Pages\CreateRecord;

class CreateSalesOrder extends CreateRecord
{
    protected static string $resource = SalesOrderResource::class;

    protected function afterSave(): void
    {
        app(SalesOrderService::class)->recalc($this->record);
    }

    protected function afterCreate(): void
    {
        app(SalesOrderService::class)->recalc($this->record);
    }
}
