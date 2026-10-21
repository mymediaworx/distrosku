<?php

namespace App\Filament\Pages\Reports;

class SalesByCustomer extends BaseReport
{
    protected static ?string $slug = 'reports/sales-by-customer';

    protected static ?string $title = 'Sales by customer';

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 1;

    protected static string $module = 'sales_reports';

    protected static array $filters = ['from', 'to', 'customer', 'department'];

    public function build(): array
    {
        return $this->svc()->salesBy('customer', $this->from, $this->to, $this->f());
    }
}
