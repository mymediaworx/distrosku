<?php

namespace App\Filament\Resources\SalesOrderResource\Pages;

use App\Filament\Resources\SalesOrderResource;
use App\Services\SalesOrderService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateSalesOrder extends CreateRecord
{
    protected static string $resource = SalesOrderResource::class;

    /** 0.20: set by "Save & confirm". */
    public bool $confirmAfterCreate = false;

    protected function getFormActions(): array
    {
        return [
            Action::make('createConfirm')->label('Save & confirm')->icon('heroicon-o-check')->action('createAndConfirm')
                ->keyBindings(['mod+shift+s']),
            $this->getCreateFormAction()->label('Save as draft')->color('gray'),
            $this->getCancelFormAction(),
        ];
    }

    /** 0.20: one click = create + confirm (compliance, credit limit, stock reservation). On failure the order stays a Draft with the reason shown. */
    public function createAndConfirm(): void
    {
        $this->confirmAfterCreate = true;
        $this->create();
    }

    protected function getCreatedNotification(): ?Notification
    {
        return $this->confirmAfterCreate ? null : parent::getCreatedNotification();
    }

    protected function afterCreate(): void
    {
        app(SalesOrderService::class)->recalc($this->record);
        if (! $this->confirmAfterCreate) {
            return;
        }
        [$so, $reason] = app(SalesOrderService::class)->tryConfirm($this->record);
        $this->record = $so;
        $reason
            ? Notification::make()->warning()->title("{$so->number} saved as draft")->body($reason)->persistent()->send()
            : Notification::make()->success()->title("{$so->number} saved and confirmed; stock reserved.")->send();
    }
}
