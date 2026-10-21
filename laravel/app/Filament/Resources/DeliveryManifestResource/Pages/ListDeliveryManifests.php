<?php

namespace App\Filament\Resources\DeliveryManifestResource\Pages;

use App\Filament\Resources\DeliveryManifestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDeliveryManifests extends ListRecords
{
    protected static string $resource = DeliveryManifestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
