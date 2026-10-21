<?php

namespace App\Filament\Pages\Reports;

class CashFlow extends BaseReport
{
    protected static ?string $slug = 'accounting/cash-flow';

    protected static ?string $title = 'Cash flow';

    protected static ?string $navigationGroup = 'Accounting';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 33;

    protected static string $module = 'financials';

    protected static array $filters = ['from', 'to', 'compare'];

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) $u && $u->canModule(static::$module);
    }

    public function build(): array
    {
        return app(\App\Accounting\FinancialStatements::class)->cashFlow($this->from, $this->to, $this->compare);
    }
}
