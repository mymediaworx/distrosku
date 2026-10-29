<?php

namespace App\Filament\Widgets;

use App\Analytics\KpiService;
use App\Analytics\ReportService as R;
use App\Services\Money;
use App\Analytics\DroppedCustomers;
use App\Filament\Pages;
use App\Filament\Resources\SalesOrderResource;
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

    /** 0.20.17: every tile clicks through to its related report / list; no link when the user cannot open the target. */
    private static function link(string $target, array $params = []): ?string
    {
        try {
            if (is_subclass_of($target, \Filament\Resources\Resource::class)) {
                return $target::canViewAny() ? $target::getUrl('index', $params) : null;
            }

            return $target::canAccess() ? $target::getUrl($params) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function open(Stat $stat, ?string $url): Stat
    {
        return $url ? $stat->url($url) : $stat;
    }

    protected function getStats(): array
    {
        $u = auth()->user();
        $k = app(KpiService::class);
        $usd = fn ($v) => '$'.number_format((float) R::d2($v), 2);
        $s = [];
        if ($u->canModule('kpi_sales')) {
            $sales = $k->sales();
            $s[] = self::open(Stat::make("Today's sales", $usd($sales['today']))->description('Invoiced today, net of returns')->icon('heroicon-o-banknotes'),
                self::link(Pages\Reports\SalesByCustomer::class, ['from' => now()->toDateString(), 'to' => now()->toDateString()]));
            $s[] = self::open(Stat::make('Month-to-date sales', $usd($sales['mtd']))->description(now()->format('F Y'))->icon('heroicon-o-chart-bar')->color('success'),
                self::link(Pages\Reports\SalesByCustomer::class, ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]));
            $d = DroppedCustomers::counts();
            $s[] = self::open(Stat::make('Customers dropped off', (string) $d[90])->description(collect(DroppedCustomers::WINDOWS)->map(fn ($w) => "{$w}d: {$d[$w]}")->implode(' · ').' (no order in)')
                ->color($d[90] ? 'warning' : 'success')->icon('heroicon-o-user-minus'), self::link(Pages\Reports\CustomersDroppedOff::class, ['window' => 90]));
        }
        if ($u->canModule('kpi_orders')) {
            $o = $k->openOrders();
            $s[] = Stat::make('Open orders', (string) $o['count'])->description(($u->canModule('kpi_sales') ? $usd($o['value']).' · ' : '').'confirmed, picked or delivered, not invoiced')->icon('heroicon-o-shopping-cart')
                ->url(self::link(SalesOrderResource::class, ['tableFilters' => ['open' => ['isActive' => true]]]));
        }
        if ($u->canModule('kpi_ar')) {
            $ar = $k->ar();
            $s[] = self::open(Stat::make('AR total', $usd($ar['total']))->description('Includes beginning balances')->icon('heroicon-o-document-text'), self::link(Pages\Reports\ArAging::class));
            $s[] = Stat::make('AR past due (31+ days)', $usd($ar['past_due']))->color(Money::of($ar['past_due'])->isPositive() ? 'danger' : 'success')->icon('heroicon-o-exclamation-triangle')->url(self::link(Pages\Reports\ArAging::class));
        }
        if ($u->canModule('kpi_finance')) {
            $s[] = self::open(Stat::make('KY excise accrued (MTD)', $usd($k->exciseMtd()))->description('Credits to 2100 this month')->icon('heroicon-o-building-library'),
                self::link(Pages\Reports\TaxSummary::class, ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]));
        }
        if ($u->canModule('kpi_inventory')) {
            $l = $k->lowStock();
            $s[] = Stat::make('Low stock', (string) $l['count'])->description($l['count'] ? implode(', ', array_slice($l['items'], 0, 3)) : 'At or below reorder point')
                ->color($l['count'] ? 'warning' : 'success')->icon('heroicon-o-archive-box-x-mark')->url(self::link(Pages\ReorderSuggestions::class));
        }

        return $s;
    }
}
