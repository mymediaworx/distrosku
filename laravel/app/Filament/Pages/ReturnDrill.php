<?php

namespace App\Filament\Pages;

use App\Filament\Resources\CreditMemoResource;
use App\Filament\Resources\PurchaseOrderResource;
use App\Filament\Resources\SalesOrderResource;
use App\Models\TaxFiling;
use App\Reports\TaxReport;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/** Drill-down from a KY return line to the invoices (sales orders), credit memos and purchase orders behind it. */
class ReturnDrill extends Page
{
    protected static string $view = 'filament.pages.return-drill';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'tax-reports/drill';

    #[Url]
    public string $type = '';

    #[Url]
    public string $period = '';

    #[Url]
    public string $line = '';

    #[Url]
    public ?int $filing = null;

    public array $report = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canModule('reports');
    }

    public function mount(): void
    {
        if ($this->filing) {
            $f = TaxFiling::findOrFail($this->filing);
            $this->type = $f->report_type;
            $this->period = $f->period_start->format('Y-m');
            $this->report = $f->data ?? [];
        }
        if (! $this->report || ! isset($this->report['drill'])) { // older snapshots have no drill data: rebuild from live data
            abort_unless(in_array($this->type, \App\Tax\ReturnLock::TYPES, true) && preg_match('/^\d{4}-\d{2}$/', $this->period), 404);
            $s = CarbonImmutable::createFromFormat('Y-m-d', $this->period.'-01')->startOfMonth();
            $this->report = TaxReport::for($this->type)->build($s, $s->endOfMonth());
        }
    }

    public function getTitle(): string
    {
        return 'Drill-down: '.($this->report['title'] ?? '').' '.$this->period.($this->line !== '' ? ' line '.$this->line : '');
    }

    public function lines(): array
    {
        return collect($this->report['summary'] ?? [])->filter(fn ($r) => isset($this->report['drill'][$r[0]]))->all();
    }

    public function refs(): array
    {
        return $this->line === '' ? [] : ($this->report['drill'][$this->line] ?? []);
    }

    public static function url(array $ref): ?string
    {
        if (! $ref['id']) {
            return null;
        }

        return match ($ref['type']) {
            'so' => SalesOrderResource::getUrl('edit', ['record' => $ref['id']]),
            'cm' => CreditMemoResource::getUrl('view', ['record' => $ref['id']]),
            'po' => PurchaseOrderResource::getUrl('edit', ['record' => $ref['id']]),
            default => null,
        };
    }
}
