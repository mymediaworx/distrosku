<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Filament\Resources\PurchaseOrderResource;
use App\Services\PurchaseOrderService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \App\Filament\Support\UpdateTrackingAction::page(),
            Actions\DeleteAction::make()->visible(fn () => in_array($this->record->status instanceof \BackedEnum ? $this->record->status->value : $this->record->status, ['draft'], true)),
        ];
    }

    protected function afterSave(): void
    {
        app(PurchaseOrderService::class)->recalc($this->record);
    }

    protected function afterCreate(): void
    {
        app(PurchaseOrderService::class)->recalc($this->record);
    }
}
