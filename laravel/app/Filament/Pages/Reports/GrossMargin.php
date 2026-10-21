<?php

namespace App\Filament\Pages\Reports;

class GrossMargin extends BaseReport
{
    protected static ?string $slug = 'reports/gross-margin';

    protected static ?string $title = 'Gross margin';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static ?int $navigationSort = 5;

    protected static string $module = 'reports';

    protected static array $filters = ['from', 'to', 'customer', 'department', 'group'];

    public function build(): array
    {
        return $this->svc()->grossMargin($this->from, $this->to, $this->f());
    }
}
