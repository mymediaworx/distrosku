<?php

namespace App\Filament\Pages;

use App\Inventory\ReorderService;
use App\Models\Warehouse;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;

/** Reorder suggestions (reorder point, sales velocity, lead time, on-order) with one-click draft POs per vendor. */
class ReorderSuggestions extends Page
{
    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?int $navigationSort = 75;

    protected static string $view = 'filament.pages.reorder-suggestions';

    protected static ?string $title = 'Reorder suggestions';

    public ?int $warehouseId = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canModule('reorder');
    }

    public static function canOrder(): bool
    {
        return (bool) auth()->user()?->canModule('reorder', 'manage');
    }

    public function mount(): void
    {
        $this->warehouseId = Warehouse::orderBy('id')->value('id');
    }

    public function getData(): array
    {
        $rows = $this->warehouseId ? app(ReorderService::class)->suggestions($this->warehouseId) : [];

        return ['groups' => collect($rows)->groupBy(fn ($r) => $r['vendor']?->id ?? 0), 'warehouses' => Warehouse::orderBy('code')->pluck('code', 'id'),
            'cfg' => ReorderService::settings()];
    }

    public function createPo(?int $vendorId = null): void
    {
        abort_unless(self::canOrder(), 403);
        try {
            $pos = app(ReorderService::class)->createDraftPos($this->warehouseId, $vendorId ? [$vendorId] : null);
            Notification::make()->success()->title('Draft PO'.(count($pos) > 1 ? 's' : '').' created: '.collect($pos)->pluck('number')->implode(', '))
                ->body('Review and send them from Purchase Orders. Nothing was sent to the vendor.')->send();
        } catch (Throwable $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
