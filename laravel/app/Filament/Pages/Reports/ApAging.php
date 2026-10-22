<?php

namespace App\Filament\Pages\Reports;

class ApAging extends BaseReport
{
    protected static ?string $slug = 'accounting/ap-aging';

    protected static ?string $title = 'AP aging';

    protected static ?string $navigationGroup = 'Bills (AP)';

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 3;

    protected static string $module = 'ap';

    protected static array $filters = ['as_of'];

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) $u && $u->canModule(static::$module);
    }

    public function build(): array
    {
        return app(\App\Accounting\ApService::class)->aging($this->as_of);
    }
}
