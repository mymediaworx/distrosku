<?php

namespace App\Filament\Resources\ShipToResource\Pages;

use App\Filament\Resources\ShipToResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListShipTos extends ListRecords
{
    protected static string $resource = ShipToResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
