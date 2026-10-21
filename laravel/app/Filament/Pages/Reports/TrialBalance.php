<?php

namespace App\Filament\Pages\Reports;

class TrialBalance extends BaseReport
{
    protected static ?string $slug = 'accounting/trial-balance';

    protected static ?string $title = 'Trial balance';

    protected static ?string $navigationGroup = 'Accounting';

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?int $navigationSort = 30;

    protected static string $module = 'financials';

    protected static array $filters = ['as_of', 'compare'];

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) $u && $u->canModule(static::$module);
    }

    public function build(): array
    {
        return app(\App\Accounting\FinancialStatements::class)->trialBalance($this->as_of, $this->compare);
    }
}
