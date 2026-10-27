<?php

namespace App\Filament\Resources\LotResource\Pages;

use App\Exceptions\WorkflowException;
use App\Filament\Resources\LotResource;
use App\Models\Lot;
use App\Models\LotContact;
use App\Warehouse\LotService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Url;

/**
 * 0.20.15 (#36): one trace screen. Search a lot number, SKU/UPC or order (SO / POS / VAN / PO) number and see every lot
 * involved: receipts, who bought it (customer + ship-to), what is on hand, COA state. Hold / release, CSV export
 * and a contacted log (who, when, how) for recall calls.
 */
class Trace extends Page
{
    protected static string $resource = LotResource::class;

    protected static string $view = 'filament.pages.lot-trace';

    protected static ?string $title = 'Trace';

    #[Url]
    public string $q = '';

    /** contact form, keyed "lotId|customerId" */
    public array $method = [];

    public array $note = [];

    public function getTrace(): array
    {
        return app(LotService::class)->trace($this->q);
    }

    public function canHold(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public function toggleHold(int $lotId): void
    {
        abort_unless($this->canHold(), 403);
        $lot = Lot::findOrFail($lotId);
        app(LotService::class)->setHold($lot, ! $lot->on_hold);
        Notification::make()->success()->title($lot->on_hold ? "Lot {$lot->lot_number} on hold" : "Lot {$lot->lot_number} released")->send();
    }

    public function logContact(int $lotId, ?int $customerId, ?int $shipToId = null): void
    {
        abort_unless(auth()->user()?->canModule('lots'), 403);
        $k = $lotId.'|'.($customerId ?? 0);
        try {
            app(LotService::class)->logContact(Lot::findOrFail($lotId), $customerId, $shipToId, (string) ($this->method[$k] ?? ''), $this->note[$k] ?? null, auth()->id());
        } catch (WorkflowException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        unset($this->method[$k], $this->note[$k]);
        Notification::make()->success()->title('Contact logged')->send();
    }

    public function methods(): array
    {
        return LotContact::METHODS;
    }
}
