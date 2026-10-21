<?php

namespace App\Filament\Resources\VendorPaymentResource\Pages;

use App\Filament\Resources\VendorPaymentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListVendorPayments extends ListRecords
{
    protected static string $resource = VendorPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('pay')->label('Pay bills')->icon('heroicon-o-banknotes')
                ->visible(fn () => auth()->user()->canModule('ap', 'manage'))
                ->form(VendorPaymentResource::payForm())->modalWidth('2xl')
                ->action(fn (array $data) => VendorPaymentResource::pay($data)),
        ];
    }
}
