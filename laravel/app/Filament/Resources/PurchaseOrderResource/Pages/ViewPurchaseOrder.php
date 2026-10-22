<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Filament\Resources\PurchaseOrderResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/** 0.20.2: read-only PO page (warehouse / receiving team) with Update tracking. */
class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \App\Filament\Support\UpdateTrackingAction::page(),
            Actions\EditAction::make(),
        ];
    }
}
