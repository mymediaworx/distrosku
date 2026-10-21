<?php

namespace App\Filament\Resources\InventoryCountResource\Pages;

use App\Exceptions\WorkflowException;
use App\Filament\Resources\InventoryCountResource;
use App\Inventory\CountService;
use App\Models\Warehouse;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInventoryCount extends CreateRecord
{
    protected static string $resource = InventoryCountResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(CountService::class)->create(Warehouse::findOrFail($data['warehouse_id']), $data['type'], (bool) ($data['blind'] ?? false),
                ['bin_ids' => $data['bin_ids'] ?? [], 'product_ids' => $data['product_ids'] ?? []], auth()->user(), $data['notes'] ?? null);
        } catch (WorkflowException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
