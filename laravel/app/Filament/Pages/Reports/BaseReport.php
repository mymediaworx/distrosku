<?php

namespace App\Filament\Pages\Reports;

use App\Analytics\ReportService;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Pages\Page;

/**
 * Shared management-report page: date range / customer / department (/ product) filters, live table, CSV + PDF export.
 * Subclasses implement build() and declare which filters they use.
 */
abstract class BaseReport extends Page
{
    protected static string $view = 'filament.pages.management-report';

    protected static ?string $navigationGroup = 'Reports';

    /** Module checked for access (config/erp.php permissions). */
    protected static string $module = 'reports';

    /** Filters shown: from, to, as_of, customer, department, product, group */
    protected static array $filters = ['from', 'to', 'customer', 'department'];

    public ?string $from = null;

    public ?string $to = null;

    public ?string $as_of = null;

    public $customer_id = null;

    public $department_id = null;

    public $product_id = null;

    public string $group = 'product';

    /** Financial statements: none | prior_period | prior_year */
    public string $compare = 'none';

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) $u && ($u->canModule(static::$module) || $u->canModule('reports'));
    }

    public function mount(): void
    {
        $this->from = request()->query('from', now()->startOfMonth()->toDateString());
        $this->to = request()->query('to', now()->toDateString());
        $this->as_of = request()->query('as_of', now()->toDateString());
        $this->customer_id = request()->query('customer_id');
        $this->compare = (string) request()->query('compare', 'none');
    }

    abstract public function build(): array;

    public function filtersUsed(): array
    {
        return static::$filters;
    }

    protected function f(): array
    {
        return array_filter(['customer_id' => $this->customer_id ?: null, 'department_id' => $this->department_id ?: null,
            'product_id' => $this->product_id ?: null, 'group' => $this->group]);
    }

    public function customerOptions(): array
    {
        return Customer::orderBy('name')->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->code} {$c->name}"])->all();
    }

    public function departmentOptions(): array
    {
        return Department::orderBy('name')->pluck('name', 'id')->all();
    }

    /** Pickers exclude archived products; reports still include their history. */
    public function productOptions(): array
    {
        return Product::notArchived()->orderBy('sku')->limit(2000)->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} {$p->name}"])->all();
    }

    public function getReport(): ?array
    {
        try {
            return $this->build();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    protected function getHeaderActions(): array
    {
        $name = fn ($ext) => str(static::getSlug())->afterLast('/')->append('_'.now()->format('Ymd_His').'.'.$ext)->toString();

        return [
            Action::make('csv')->label('CSV')->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => response()->streamDownload(fn () => print (ReportService::toCsv($this->build())), $name('csv'), ['Content-Type' => 'text/csv'])),
            Action::make('pdf')->label('PDF')->icon('heroicon-o-document-arrow-down')
                ->action(fn () => response()->streamDownload(fn () => print (ReportService::toPdf($this->build())), $name('pdf'), ['Content-Type' => 'application/pdf'])),
        ];
    }

    protected function svc(): ReportService
    {
        return app(ReportService::class);
    }
}
