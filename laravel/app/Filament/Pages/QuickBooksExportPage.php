<?php

namespace App\Filament\Pages;

use App\Accounting\QuickBooksExport;
use App\Audit\Audit;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/** #40: Accounting > QuickBooks export (owner/admin): the GL for a date range as a QuickBooks import file. */
class QuickBooksExportPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.quickbooks-export';

    protected static ?string $navigationGroup = 'Accounting';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $navigationLabel = 'QuickBooks export';

    protected static ?string $title = 'QuickBooks export';

    protected static ?string $slug = 'accounting/quickbooks-export';

    protected static ?int $navigationSort = 50;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public function mount(): void
    {
        $this->form->fill(['from' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'to' => now()->subMonthNoOverflow()->endOfMonth()->toDateString(), 'format' => 'qbo']);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->columns(3)->schema([
            Forms\Components\DatePicker::make('from')->required(),
            Forms\Components\DatePicker::make('to')->required()->afterOrEqual('from'),
            Forms\Components\Select::make('format')->options(QuickBooksExport::FORMATS)->required(),
        ]);
    }

    public function download()
    {
        $d = $this->form->getState();
        $file = app(QuickBooksExport::class)->build($d['from'], $d['to'], $d['format']);
        Audit::action('quickbooks_export', null, "QuickBooks export {$d['from']} to {$d['to']} ({$d['format']}): {$file['journals']} journal entries", ['from' => $d['from'], 'to' => $d['to'], 'format' => $d['format']]);

        return response()->streamDownload(fn () => print ($file['body']), $file['name'], ['Content-Type' => $file['mime']]);
    }
}
