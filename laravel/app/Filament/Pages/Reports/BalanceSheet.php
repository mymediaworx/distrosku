<?php

namespace App\Filament\Pages\Reports;

class BalanceSheet extends BaseReport
{
    protected static ?string $slug = 'accounting/balance-sheet';

    protected static ?string $title = 'Balance sheet';

    protected static ?string $navigationGroup = 'Accounting';

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?int $navigationSort = 32;

    protected static string $module = 'financials';

    protected static array $filters = ['as_of', 'compare'];

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) $u && $u->canModule(static::$module);
    }

    public function build(): array
    {
        return app(\App\Accounting\FinancialStatements::class)->balanceSheet($this->as_of, $this->compare);
    }
}
