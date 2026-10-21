<?php

namespace App\Filament\Pages\Reports;

class ProfitLoss extends BaseReport
{
    protected static ?string $slug = 'accounting/profit-loss';

    protected static ?string $title = 'Profit & loss';

    protected static ?string $navigationGroup = 'Accounting';

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?int $navigationSort = 31;

    protected static string $module = 'financials';

    protected static array $filters = ['from', 'to', 'compare'];

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) $u && $u->canModule(static::$module);
    }

    public function build(): array
    {
        return app(\App\Accounting\FinancialStatements::class)->profitLoss($this->from, $this->to, $this->compare);
    }
}
