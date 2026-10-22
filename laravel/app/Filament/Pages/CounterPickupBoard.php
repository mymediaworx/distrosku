<?php

namespace App\Filament\Pages;

use App\Enums\SalesOrderStatus as S;
use App\Filament\Support\OrderActions;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Sales\PickupBoard;
use App\Services\SalesOrderService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/** 0.20.4: Counter pickup board: open pickup orders as columns To pick / Picking / Ready for pickup / Picked up today. */
class CounterPickupBoard extends Page
{
    protected static string $view = 'filament.pages.counter-pickup-board';

    protected static ?string $navigationGroup = 'Counter Pickup'; // 0.20.5: own sidebar group after Inventory (slug/route unchanged)

    protected static ?string $navigationIcon = 'heroicon-o-view-columns';

    protected static ?string $title = 'Counter pickup board';

    protected static ?string $slug = 'sales/counter-pickup';

    protected static ?int $navigationSort = 1;

    public ?int $warehouseId = null;

    public string $search = '';

    public bool $tv = false;

    protected $queryString = ['warehouseId' => ['as' => 'wh', 'except' => null], 'tv' => ['except' => false]];

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) $u && ($u->canModule('sales_orders', 'view') || $u->canModule('warehouse_scan', 'view') || $u->canModule('pickup_signature', 'view'));
    }

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    public function toggleTv(): void
    {
        $this->tv = ! $this->tv;
    }

    public function warehouses(): array
    {
        return Warehouse::orderBy('code')->pluck('code', 'id')->all();
    }

    public function columns(): array
    {
        return PickupBoard::columns($this->warehouseId ?: null, $this->search);
    }

    private function order(array $arguments): SalesOrder
    {
        return SalesOrder::findOrFail((int) ($arguments['id'] ?? 0));
    }

    private function attempt(callable $fn): void
    {
        try {
            $msg = $fn();
            Notification::make()->success()->title(is_string($msg) ? $msg : 'Done')->send();
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->persistent()->send();
        }
    }

    private function deny(bool $ok): void
    {
        if (! $ok) {
            throw new AuthorizationException('You do not have permission for this step.');
        }
    }

    public function startPickingAction(): Action
    {
        return Action::make('startPicking')->label('Start picking')->icon('heroicon-o-hand-raised')->size('sm')
            ->action(function (array $arguments) {
                $this->deny(PickupBoard::canMove());
                $this->attempt(fn () => [PickupBoard::startPicking($this->order($arguments)), 'Picking started.'][1]);
            });
    }

    public function unstartAction(): Action
    {
        return Action::make('unstart')->label('Back to To pick')->icon('heroicon-o-arrow-uturn-left')->color('gray')->size('sm')->link()
            ->action(function (array $arguments) {
                $this->deny(PickupBoard::canMove());
                $this->attempt(fn () => [PickupBoard::unstart($this->order($arguments)), 'Moved back to To pick.'][1]);
            });
    }

    public function markReadyAction(): Action
    {
        return Action::make('markReady')->label('Mark ready')->icon('heroicon-o-archive-box')->color('info')->size('sm')
            ->requiresConfirmation()->modalHeading('Mark ready for pickup?')->modalDescription('The order is marked Picked and moves to Ready for pickup.')
            ->action(function (array $arguments) {
                $this->deny(PickupBoard::canMove());
                $this->attempt(fn () => [PickupBoard::markReady($this->order($arguments)), 'Ready for pickup.'][1]);
            });
    }

    public function pickedUpAction(): Action
    {
        return Action::make('pickedUp')->label('Picked up')->icon('heroicon-o-check-badge')->color('success')->size('sm')
            ->requiresConfirmation()->modalHeading('Customer picked up?')->modalDescription('Stock goes out and the invoice is posted (Deliver & invoice).')
            ->action(function (array $arguments) {
                $this->deny(PickupBoard::canHandOver());
                $this->attempt(function () use ($arguments) {
                    $o = PickupBoard::pickedUp($this->order($arguments));

                    return "Picked up and invoiced ({$o->number}).";
                });
            });
    }

    public function collectAction(): Action
    {
        return Action::make('collect')->label('Collect payment')->icon('heroicon-o-banknotes')->color('success')->size('sm')
            ->record(fn (array $arguments) => SalesOrder::find((int) ($arguments['id'] ?? 0)))
            ->modalHeading(fn (array $arguments) => ($o = SalesOrder::find((int) ($arguments['id'] ?? 0))) && in_array($o->status, [S::Confirmed, S::Picked], true)
                ? "Picked up & paid: {$o->number}" : 'Record payment')
            ->modalDescription(fn (array $arguments) => ($o = SalesOrder::find((int) ($arguments['id'] ?? 0)))
                ? (in_array($o->status, [S::Confirmed, S::Picked], true) ? 'Hand-over, invoice and payment in one step. ' : '').'Open balance $'.number_format((float) $o->balance(), 2).'.' : null)
            ->fillForm(fn (array $arguments) => ['method' => 'cash', 'amount' => number_format((float) (SalesOrder::find((int) ($arguments['id'] ?? 0))?->balance() ?? 0), 2, '.', '')])
            ->form(OrderActions::paymentFields())
            ->action(function (array $arguments, array $data) {
                $this->deny(PickupBoard::canHandOver());
                $this->attempt(function () use ($arguments, $data) {
                    $o = $this->order($arguments);
                    $svc = app(SalesOrderService::class);
                    if (in_array($o->status, [S::Confirmed, S::Picked], true)) {
                        $from = PickupBoard::stage($o);
                        $p = $svc->deliverAndCollect($o, $data['method'], $data['amount'] ?? null, $data['reference'] ?? null, $data['override_reason'] ?? null);
                        PickupBoard::afterCollect($o, $from);

                        return 'Picked up and invoiced. '.OrderActions::payMessage($p, $o);
                    }
                    $p = $svc->settle($o, $data['method'], $data['amount'] ?? null, $data['reference'] ?? null, $data['override_reason'] ?? null);

                    return OrderActions::payMessage($p, $o);
                });
            });
    }
}
