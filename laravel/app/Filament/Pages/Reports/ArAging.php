<?php

namespace App\Filament\Pages\Reports;

class ArAging extends BaseReport
{
    protected static ?string $slug = 'reports/ar-aging';

    protected static ?string $title = 'AR aging';

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 6;

    protected static string $module = 'reports';

    protected static array $filters = ['as_of', 'customer'];

    public function build(): array
    {
        return $this->svc()->arAging($this->as_of, $this->f());
    }
}
