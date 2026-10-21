<?php

namespace App\Filament\Pages\Reports;

class SalesByDepartment extends BaseReport
{
    protected static ?string $slug = 'reports/sales-by-department';

    protected static ?string $title = 'Sales by department';

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 3;

    protected static string $module = 'sales_reports';

    protected static array $filters = ['from', 'to', 'customer', 'department'];

    public function build(): array
    {
        return $this->svc()->salesBy('department', $this->from, $this->to, $this->f());
    }
}
