<?php

namespace App\Filament\Pages;

use App\Imports\ImportRegistry;
use App\Imports\ImportRunner;
use App\Imports\ScheduledCostService;
use App\Models\ImportBatch;
use App\Models\ImportMapping;
use App\Models\ScheduledCost;
use App\Models\Vendor;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;
use Throwable;

/** Phase 17: spreadsheet imports + vendor price files. Upload -> map -> dry run -> commit. Owners/admins (config erp.permissions.imports). */
class Imports extends Page
{
    use WithFileUploads;

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?int $navigationSort = 80;

    protected static ?string $title = 'Imports';

    protected static string $view = 'filament.pages.imports';

    #[Url]
    public ?int $batch = null;

    public string $type = 'customers';

    public ?int $vendorId = null;

    public ?string $effectiveOn = null;

    public $file = null;

    /** @var array<string, ?string> field => header */
    public array $mapping = [];

    public string $mappingName = '';

    public ?int $loadMappingId = null;

    public string $show = 'all';

    public static function canAccess(): bool
    {
        return ImportRunner::canUse(auth()->user());
    }

    public function mount(): void
    {
        if ($b = $this->current()) {
            $this->mapping = (array) $b->mapping;
        }
    }

    public function current(): ?ImportBatch
    {
        return $this->batch ? ImportBatch::find($this->batch) : null;
    }

    private function guard(): void
    {
        abort_unless(ImportRunner::canUse(auth()->user()), 403);
    }

    private function fail(Throwable $e): void
    {
        Notification::make()->danger()->title($e->getMessage())->send();
    }

    public function upload(ImportRunner $r): void
    {
        $this->guard();
        $this->validate(['file' => 'required|file|max:51200', 'type' => 'required|in:'.implode(',', array_keys(ImportRegistry::TYPES)),
            'effectiveOn' => 'nullable|date']);
        try {
            $b = $r->upload($this->file, $this->type, auth()->user(), $this->vendorId, $this->effectiveOn);
            $this->file = null;
            $this->batch = $b->id;
            $this->mapping = (array) $b->mapping;
            Notification::make()->success()->title("Read {$b->total_rows} rows. Check the column mapping, then run the dry run.")->send();
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    public function saveMapping(ImportRunner $r): void
    {
        $this->guard();
        try {
            $b = $this->current();
            $r->setMapping($b, $this->mapping);
            if (trim($this->mappingName) === '') {
                throw new \RuntimeException('Give the mapping template a name.');
            }
            $r->saveMapping($b->fresh(), trim($this->mappingName), auth()->user());
            Notification::make()->success()->title('Mapping template saved.')->send();
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    public function loadMapping(): void
    {
        $this->guard();
        $b = $this->current();
        if ($m = ImportMapping::where('type', $b->type)->where('vendor_id', $b->vendor_id)->find($this->loadMappingId)) {
            $this->mapping = $m->mapping;
            $this->mappingName = $m->name;
        }
    }

    public function dryRun(ImportRunner $r): void
    {
        $this->guard();
        try {
            $b = $this->current();
            $r->setMapping($b, $this->mapping);
            $r->startValidation($b->fresh());
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    public function commit(ImportRunner $r): void
    {
        $this->guard();
        try {
            $r->startCommit($this->current(), auth()->user());
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    /** wire:poll: advance a chunk even when no queue worker is running. */
    public function tick(ImportRunner $r): void
    {
        $this->guard();
        if ($b = $this->current()) {
            $r->step($b);
        }
    }

    public function applyDueCosts(ScheduledCostService $s): void
    {
        $this->guard();
        Notification::make()->success()->title('Applied '.$s->applyDue().' scheduled cost change(s).')->send();
    }

    public function closeBatch(): void
    {
        $this->batch = null;
        $this->mapping = [];
    }

    protected function getViewData(): array
    {
        $b = $this->current();
        $rows = $b ? $b->rows()->when($this->show === 'errors', fn ($q) => $q->whereIn('status', ['error', 'failed']))
            ->when($this->show === 'unmatched', fn ($q) => $q->where('status', 'unmatched'))
            ->when($this->show === 'changes', fn ($q) => $q->whereIn('action', ['create', 'update', 'schedule']))
            ->orderBy('row_no')->limit(100)->get() : collect();

        return [
            'b' => $b, 'handler' => $b?->handler(), 'rows' => $rows,
            'types' => ImportRegistry::options(false), 'vendorTypes' => ImportRegistry::options(true),
            'vendors' => Vendor::orderBy('name')->pluck('name', 'id'),
            'savedMappings' => $b ? ImportMapping::where('type', $b->type)->where('vendor_id', $b->vendor_id)->orderBy('name')->pluck('name', 'id') : collect(),
            'recent' => ImportBatch::with('user', 'vendor')->latest('id')->limit(15)->get(),
            'pendingCosts' => ScheduledCost::whereNull('applied_at')->count(),
        ];
    }
}
