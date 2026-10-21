<?php

namespace App\Filament\Widgets;

use App\Analytics\KpiService;
use Filament\Widgets\Widget;

/** Top 5 customers and products by net sales this month. */
class TopSellers extends Widget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 4;

    protected static string $view = 'filament.widgets.top-sellers';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $u = auth()->user();

        return (bool) $u && ($u->canModule('kpi_sales') || $u->canModule('kpi_inventory'));
    }

    protected function getViewData(): array
    {
        $u = auth()->user();
        $k = app(KpiService::class);

        return [
            'customers' => $u->canModule('kpi_sales') ? $k->top('customer') : null,
            'products' => $k->top('product'),
            'showMoney' => $u->canModule('kpi_sales'),
        ];
    }
}
