<?php

namespace App\Filament\Pages\Reports;

class InventoryMovement extends BaseReport
{
    protected static ?string $slug = 'reports/inventory-movement';

    protected static ?string $title = 'Inventory movement';

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?int $navigationSort = 9;

    protected static string $module = 'inventory_reports';

    protected static array $filters = ['from', 'to', 'department', 'product'];

    public function build(): array
    {
        return $this->svc()->inventoryMovement($this->from, $this->to, $this->f());
    }
}
