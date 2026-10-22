<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ExpectedInbound;
use App\Purchasing\ReceivingAlerts;
use Filament\Widgets\Widget;

/** 0.20.1 dashboard: inbound POs for the receiving team (overdue / today / this week / later). */
class ExpectedInboundWidget extends Widget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected static string $view = 'filament.widgets.expected-inbound';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->canModule('purchase_orders', 'receive');
    }

    protected function getViewData(): array
    {
        $pos = ExpectedInbound::query()->orderByRaw('expected_at IS NULL')->orderBy('expected_at')->limit(100)->get();
        $by = $pos->groupBy(fn ($p) => ReceivingAlerts::bucket($p));

        return [
            'counts' => ['overdue' => count($by['overdue'] ?? []), 'today' => count($by['today'] ?? []),
                'week' => count($by['tomorrow'] ?? []) + count($by['week'] ?? []), 'later' => count($by['later'] ?? []) + count($by['unscheduled'] ?? [])],
            'next' => $pos->take(8),
            'url' => ExpectedInbound::getUrl(),
            'canScan' => auth()->user()->canModule('warehouse_scan', 'manage'),
        ];
    }
}
