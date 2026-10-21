<?php

namespace App\Filament\Pages\Reports;

class TaxSummary extends BaseReport
{
    protected static ?string $slug = 'reports/tax-summary';

    protected static ?string $title = 'Tax & excise summary';

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?int $navigationSort = 10;

    protected static string $module = 'reports';

    protected static array $filters = ['from', 'to'];

    public function build(): array
    {
        return $this->svc()->taxSummary($this->from, $this->to);
    }
}
