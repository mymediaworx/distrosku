<?php

namespace App\Filament\Pages\Reports;

class InventoryValuation extends BaseReport
{
    protected static ?string $slug = 'reports/inventory-valuation';

    protected static ?string $title = 'Inventory valuation';

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?int $navigationSort = 8;

    protected static string $module = 'inventory_reports';

    protected static array $filters = ['department', 'product'];

    public function build(): array
    {
        return $this->svc()->inventoryValuation($this->f());
    }
}
