<?php

namespace App\Filament\Pages\Reports;

class SalesByProduct extends BaseReport
{
    protected static ?string $slug = 'reports/sales-by-product';

    protected static ?string $title = 'Sales by product';

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?int $navigationSort = 2;

    protected static string $module = 'sales_reports';

    protected static array $filters = ['from', 'to', 'customer', 'department', 'product'];

    public function build(): array
    {
        return $this->svc()->salesBy('product', $this->from, $this->to, $this->f());
    }
}
