<?php

namespace App\Filament\Pages\Reports;

class CustomerStatement extends BaseReport
{
    protected static ?string $slug = 'reports/customer-statement';

    protected static ?string $title = 'Customer statement';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 7;

    protected static string $module = 'reports';

    protected static array $filters = ['from', 'to', 'customer'];

    public function build(): array
    {
        return $this->customer_id ? $this->svc()->statement((int) $this->customer_id, $this->from, $this->to)
            : ['key' => 'statement', 'title' => 'Customer statement', 'period' => 'Pick a customer', 'columns' => ['Date', 'Description', 'Charges', 'Credits', 'Balance'], 'rows' => [], 'totals' => null, 'notes' => ['Choose a customer to build the statement.']];
    }
}
