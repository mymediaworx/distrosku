<?php

namespace App\Filament\Resources\LotResource\Pages;

use App\Filament\Resources\LotResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListLots extends ListRecords
{
    protected static string $resource = LotResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('recall')->label('Recall look-up')->icon('heroicon-o-magnifying-glass')->url(RecallLookup::getUrl())];
    }
}
