<?php

namespace App\Filament\Widgets;

use App\Analytics\KpiService;
use Filament\Widgets\ChartWidget;

class ArAgingChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected static ?string $heading = 'AR aging';

    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->canModule('kpi_ar');
    }

    protected function getData(): array
    {
        $b = app(KpiService::class)->ar()['buckets'];

        return ['datasets' => [['label' => 'Open AR ($)', 'data' => array_map(fn ($v) => (float) $v, array_values($b)),
            'backgroundColor' => ['#22c55e', '#facc15', '#f97316', '#ef4444']]], 'labels' => ['0-30', '31-60', '61-90', '90+']];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
