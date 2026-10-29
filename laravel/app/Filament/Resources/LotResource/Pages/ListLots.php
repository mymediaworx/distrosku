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
        return [Action::make('trace')->label('Trace')->icon('heroicon-o-magnifying-glass-circle')->url(Trace::getUrl()),
            Action::make('review')->label(fn () => 'Needs review ('.\App\Models\LotReviewItem::where('status', 'open')->count().')')->icon('heroicon-o-exclamation-triangle')
                ->url(\App\Filament\Resources\LotReviewItemResource::getUrl()),
            Action::make('recall')->label('Recall look-up')->icon('heroicon-o-magnifying-glass')->url(RecallLookup::getUrl())];
    }
}
