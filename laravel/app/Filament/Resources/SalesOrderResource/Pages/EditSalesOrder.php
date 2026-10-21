<?php

namespace App\Filament\Resources\SalesOrderResource\Pages;

use App\Filament\Resources\SalesOrderResource;
use App\Services\SalesOrderService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSalesOrder extends EditRecord
{
    protected static string $resource = SalesOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->visible(fn () => in_array($this->record->status instanceof \BackedEnum ? $this->record->status->value : $this->record->status, ['draft'], true)),
        ];
    }

    protected function afterSave(): void
    {
        app(SalesOrderService::class)->recalc($this->record);
    }

    protected function afterCreate(): void
    {
        app(SalesOrderService::class)->recalc($this->record);
    }
}
