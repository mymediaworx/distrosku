<?php

namespace App\Filament\Pages\Reports;

class PaymentsReceived extends BaseReport
{
    protected static ?string $slug = 'reports/payments-received';

    protected static ?string $title = 'Payments received';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 11;

    protected static string $module = 'reports';

    protected static array $filters = ['from', 'to', 'customer'];

    public function build(): array
    {
        return $this->svc()->paymentsReceived($this->from, $this->to, $this->f());
    }
}
