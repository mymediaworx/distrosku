<?php

namespace App\Filament\Resources\SalesOrderResource\Pages;

use App\Filament\Resources\SalesOrderResource;
use App\Filament\Support\OrderActions;
use App\Services\SalesOrderService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSalesOrder extends EditRecord
{
    protected static string $resource = SalesOrderResource::class;

    protected function getHeaderActions(): array
    {
        // 0.20: the order-to-cash steps are buttons on the order page (only the ones that apply show).
        return [
            ...OrderActions::flow(Actions\Action::class),
            Actions\Action::make('invoicePdf')->label('Invoice PDF')->icon('heroicon-o-document-arrow-down')->color('gray')
                ->visible(fn () => $this->record->status === \App\Enums\SalesOrderStatus::Invoiced)->url(fn () => route('docs.invoice', $this->record)),
            Actions\DeleteAction::make()->visible(fn () => in_array($this->record->status instanceof \BackedEnum ? $this->record->status->value : $this->record->status, ['draft'], true)),
        ];
    }

    protected function afterSave(): void
    {
        app(SalesOrderService::class)->recalc($this->record);
    }
}
