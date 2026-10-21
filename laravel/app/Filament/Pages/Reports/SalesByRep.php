<?php

namespace App\Filament\Pages\Reports;

class SalesByRep extends BaseReport
{
    protected static ?string $slug = 'reports/sales-by-rep';

    protected static ?string $title = 'Sales by rep';

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?int $navigationSort = 4;

    protected static string $module = 'reports';

    protected static array $filters = ['from', 'to', 'customer', 'department'];

    public function build(): array
    {
        return $this->svc()->salesBy('rep', $this->from, $this->to, $this->f());
    }
}
