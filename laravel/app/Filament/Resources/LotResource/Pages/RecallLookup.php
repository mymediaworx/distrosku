<?php

namespace App\Filament\Resources\LotResource\Pages;

use App\Filament\Resources\LotResource;
use App\Warehouse\LotService;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Url;

/** Who received a lot: customer, ship-to, order, date, qty, plus what is still on hand. */
class RecallLookup extends Page
{
    protected static string $resource = LotResource::class;

    protected static string $view = 'filament.pages.recall-lookup';

    protected static ?string $title = 'Recall look-up';

    #[Url]
    public string $lot = '';

    #[Url]
    public ?int $product = null;

    public function getReport(): ?array
    {
        return trim($this->lot) === '' ? null : app(LotService::class)->recall($this->lot, $this->product);
    }
}
