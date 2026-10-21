<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use App\Services\CustomerBalanceService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('statement')->label('Statement PDF')->icon('heroicon-o-document-arrow-down')
                ->url(fn () => route('docs.statement', $this->record)),
            Actions\Action::make('mkt')->label('Open in BrandWorx Marketing')->icon('heroicon-o-arrow-top-right-on-square')
                ->visible(fn () => \App\Marketing\MarketingSettings::enabled() && $this->record->ghl_contact_id && auth()->user()?->canModule('marketing', 'manage'))
                ->url(fn () => \App\Marketing\MarketingSettings::contactUrl($this->record->ghl_contact_id), shouldOpenInNewTab: true),
            Actions\DeleteAction::make()->visible(fn () => \App\Filament\Resources\CustomerResource::canDeleteAny()),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (CustomerBalanceService::canManage(auth()->user())) {
            $data['beginning_balance'] = $this->record->beginning_balance;
            $data['beginning_balance_date'] = $this->record->beginning_balance_date?->toDateString();
        }

        return $data;
    }

    protected function afterSave(): void
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
        app(CustomerBalanceService::class)->setBeginningBalance($this->record->refresh(), $d['beginning_balance'] ?: 0,
            $d['beginning_balance_date'] ?: null, auth()->user(), $d['beginning_balance_note'] ?? null);
    }
}
