<?php

namespace App\Filament\Widgets;

use App\Analytics\KpiService;
use Filament\Widgets\ChartWidget;

class SalesTrendChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected static ?string $heading = 'Net sales trend';

    protected static ?string $pollingInterval = '60s';

    public ?string $filter = '30';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->canModule('kpi_sales');
    }

    protected function getFilters(): ?array
    {
        return ['30' => 'Last 30 days', '90' => 'Last 90 days'];
    }

    protected function getData(): array
    {
        $t = app(KpiService::class)->trend((int) ($this->filter ?: 30));

        return ['datasets' => [['label' => 'Net sales ($)', 'data' => $t['values'], 'borderColor' => '#f59e0b', 'backgroundColor' => 'rgba(245,158,11,.15)', 'fill' => true, 'tension' => .3]], 'labels' => $t['labels']];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
