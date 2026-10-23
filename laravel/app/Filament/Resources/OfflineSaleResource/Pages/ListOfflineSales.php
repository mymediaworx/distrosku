<?php

namespace App\Filament\Resources\OfflineSaleResource\Pages;

use App\Filament\Resources\OfflineSaleResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;

class ListOfflineSales extends ListRecords
{
    protected static string $resource = OfflineSaleResource::class;

    public function getTabs(): array
    {
        return [
            'review' => Tab::make('Needs review')->badge(fn () => OfflineSaleResource::reviewCount() ?: null)->badgeColor('danger')
                ->modifyQueryUsing(fn ($query) => $query->where('status', 'needs_review')),
            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return OfflineSaleResource::reviewCount() ? 'review' : 'all';
    }
}
