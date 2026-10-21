<?php

namespace App\Filament\Resources\DeliveryManifestResource\Pages;

use App\Filament\Resources\DeliveryManifestResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDeliveryManifest extends EditRecord
{
    protected static string $resource = DeliveryManifestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
