<?php

namespace App\Filament\Pages\Reports;

use App\Analytics\DroppedCustomers;

/** 0.20.17: click-through target of the dashboard "Customers dropped off" tile. */
class CustomersDroppedOff extends BaseReport
{
    protected static ?string $slug = 'reports/customers-dropped-off';

    protected static ?string $title = 'Customers dropped off';

    protected static ?string $navigationIcon = 'heroicon-o-user-minus';

    protected static ?int $navigationSort = 12;

    protected static string $module = 'sales_reports';

    protected static array $filters = ['window'];

    public function build(): array
    {
        return DroppedCustomers::report($this->window);
    }
}
