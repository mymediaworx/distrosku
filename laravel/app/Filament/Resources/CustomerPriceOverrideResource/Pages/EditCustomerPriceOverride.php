<?php

namespace App\Filament\Resources\CustomerPriceOverrideResource\Pages;

use App\Filament\Concerns\InteractsWithSpecialPriceProductLookup;
use App\Filament\Resources\CustomerPriceOverrideResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCustomerPriceOverride extends EditRecord
{
    use InteractsWithSpecialPriceProductLookup;

    protected static string $resource = CustomerPriceOverrideResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->productLookupMulti = false;
        $this->seedPickedFromRecord();
    }
}
