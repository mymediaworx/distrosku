<?php

namespace App\Filament\Widgets;

use App\Analytics\KpiService;
use App\Analytics\ReportService as R;
use App\Services\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Live KPI tiles. Each tile is shown only to roles with the matching kpi_* module (config/erp.php). */
class KpiStats extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $u = auth()->user();

        return (bool) $u && collect(['kpi_sales', 'kpi_ar', 'kpi_orders', 'kpi_inventory', 'kpi_finance'])->contains(fn ($m) => $u->canModule($m));
    }

    protected function getStats(): array
    {
        $u = auth()->user();
        $k = app(KpiService::class);
        $usd = fn ($v) => '$'.number_format((float) R::d2($v), 2);
        $s = [];
        if ($u->canModule('kpi_sales')) {
            $sales = $k->sales();
            $s[] = Stat::make("Today's sales", $usd($sales['today']))->description('Invoiced today, net of returns')->icon('heroicon-o-banknotes');
            $s[] = Stat::make('Month-to-date sales', $usd($sales['mtd']))->description(now()->format('F Y'))->icon('heroicon-o-chart-bar')->color('success');
        }
        if ($u->canModule('kpi_orders')) {
            $o = $k->openOrders();
            $s[] = Stat::make('Open orders', (string) $o['count'])->description(($u->canModule('kpi_sales') ? $usd($o['value']).' · ' : '').'confirmed, picked or delivered, not invoiced')->icon('heroicon-o-shopping-cart');
        }
        if ($u->canModule('kpi_ar')) {
            $ar = $k->ar();
            $s[] = Stat::make('AR total', $usd($ar['total']))->description('Includes beginning balances')->icon('heroicon-o-document-text');
            $s[] = Stat::make('AR past due (31+ days)', $usd($ar['past_due']))->color(Money::of($ar['past_due'])->isPositive() ? 'danger' : 'success')->icon('heroicon-o-exclamation-triangle');
        }
        if ($u->canModule('kpi_finance')) {
            $s[] = Stat::make('KY excise accrued (MTD)', $usd($k->exciseMtd()))->description('Credits to 2100 this month')->icon('heroicon-o-building-library');
        }
        if ($u->canModule('kpi_inventory')) {
            $l = $k->lowStock();
            $s[] = Stat::make('Low stock', (string) $l['count'])->description($l['count'] ? implode(', ', array_slice($l['items'], 0, 3)) : 'At or below reorder point')
                ->color($l['count'] ? 'warning' : 'success')->icon('heroicon-o-archive-box-x-mark');
        }

        return $s;
    }
}
