<?php

namespace App\Filament\Resources\BuydownResource\Pages;

use App\Filament\Resources\BuydownResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBuydowns extends ListRecords
{
    protected static string $resource = BuydownResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
