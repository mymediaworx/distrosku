<?php

namespace App\Filament\Resources\CustomerPriceOverrideResource\Pages;

use App\Filament\Resources\CustomerPriceOverrideResource;
use App\Models\CustomerPriceOverride;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateCustomerPriceOverride extends CreateRecord
{
    protected static string $resource = CustomerPriceOverrideResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval',
            $data['product_ids'] ?? (($data['product_id'] ?? null) ? [(int) $data['product_id']] : [])
        ))));
        if ($ids === []) {
            throw ValidationException::withMessages(['product_id' => 'Select at least one product (search, scan, or browse).']);
        }
        $data['product_ids'] = $ids;
        $data['product_id'] = $ids[0];
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $ids = $data['product_ids'] ?? [$data['product_id']];
        unset($data['product_ids']);
        $first = null;
        $created = 0;
        foreach ($ids as $pid) {
            $row = CustomerPriceOverride::create([...$data, 'product_id' => $pid]);
            $first ??= $row;
            $created++;
        }
        if ($created > 1) {
            Notification::make()->success()->title("Created {$created} special prices")->send();
        }

        return $first;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
