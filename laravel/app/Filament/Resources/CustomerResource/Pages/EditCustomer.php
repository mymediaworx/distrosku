<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use App\Marketing\MarketingSettings;
use App\Services\CustomerBalanceService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\RelationManagers\RelationManagerConfiguration;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected static string $view = 'filament.resources.customer-resource.pages.edit-customer';

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('statement')->label('Statement PDF')->icon('heroicon-o-document-arrow-down')
                ->url(fn () => route('docs.statement', $this->record)),
            Actions\Action::make('mkt')->label('Open in Marketing')->icon('heroicon-o-arrow-top-right-on-square')
                ->visible(fn () => MarketingSettings::enabled() && $this->record->ghl_contact_id && auth()->user()?->canModule('marketing', 'manage'))
                ->url(fn () => MarketingSettings::contactUrl($this->record->ghl_contact_id), shouldOpenInNewTab: true),
            Actions\DeleteAction::make()->visible(fn () => CustomerResource::canDeleteAny()),
        ];
    }

    /**
     * Managers for the mid-form tabs (same filter as Filament's getRelationManagers).
     * Bottom RMs are suppressed by the custom page view.
     *
     * @return array<int|string, class-string<RelationManager>|RelationGroup|RelationManagerConfiguration>
     */
    public function getEmbeddedRelationManagers(): array
    {
        return parent::getRelationManagers();
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (CustomerBalanceService::canManage(auth()->user())) {
            $data['beginning_balance'] = number_format((float) ($this->record->beginning_balance ?? 0), 2, '.', '');
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
