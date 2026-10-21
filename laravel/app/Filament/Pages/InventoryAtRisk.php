<?php

namespace App\Filament\Pages;

use App\Compliance\Eligibility;
use App\Models\ProductCoa;
use App\Models\ShipTo;
use Filament\Pages\Page;

/** 0.18: stock that becomes blocked by an eligibility rule on a future date, per state; plus COA expiry warnings. */
class InventoryAtRisk extends Page
{
    protected static ?string $navigationGroup = 'Compliance';

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Inventory at risk';

    protected static ?string $title = 'Inventory at risk';

    protected static string $view = 'filament.pages.inventory-at-risk';

    public string $state = '';

    public int $horizon = 120;

    public bool $includeDrafts = false;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canModule('reports');
    }

    public function mount(): void
    {
        $this->state = strtoupper((string) config('erp.home_state', 'KY'));
    }

    public function getData(): array
    {
        $states = ShipTo::query()->distinct()->orderBy('state')->pluck('state')->map(fn ($s) => strtoupper($s))->push($this->state)->unique()->sort()->values();
        $state = preg_match('/^[A-Z]{2}$/', strtoupper($this->state)) ? strtoupper($this->state) : 'KY';
        $rows = app(Eligibility::class)->atRisk($state, max(1, min(730, $this->horizon)), $this->includeDrafts);

        return ['states' => $states, 'rows' => $rows, 'total' => array_sum(array_column($rows, 'value')),
            'stranded' => array_sum(array_column($rows, 'stranded_value')), 'coas' => ProductCoa::expiringSoon()->limit(200)->get(), 'warnDays' => ProductCoa::warnDays()];
    }
}
