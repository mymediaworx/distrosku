<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use App\Services\CustomerBalanceService;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    protected function afterCreate(): void
    {
        $this->saveBeginningBalance();
    }

    private function saveBeginningBalance(): void
    {
        if (! CustomerBalanceService::canManage(auth()->user())) {
            return;
        }
        $d = $this->form->getRawState();
        if (! array_key_exists('beginning_balance', $d)) {
            return;
        }
        $amount = round((float) ($d['beginning_balance'] ?: 0), 2);
        app(CustomerBalanceService::class)->setBeginningBalance(
            $this->record->refresh(),
            $amount,
            $d['beginning_balance_date'] ?: null,
            auth()->user(),
            $d['beginning_balance_note'] ?? null
        );
    }
}
