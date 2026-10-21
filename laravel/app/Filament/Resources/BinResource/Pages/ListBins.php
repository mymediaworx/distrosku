<?php

namespace App\Filament\Resources\BinResource\Pages;

use App\Filament\Resources\BinResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBins extends ListRecords
{
    protected static string $resource = BinResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('labels')->label('Print all bin labels')->icon('heroicon-o-qr-code')
                ->url(fn () => route('wh.print.bins'), shouldOpenInNewTab: true),
            CreateAction::make(),
        ];
    }
}
