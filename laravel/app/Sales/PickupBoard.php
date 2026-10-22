<?php

namespace App\Sales;

use App\Audit\Audit;
use App\Delivery\SignatureService;
use App\Enums\Role;
use App\Enums\SalesOrderStatus as S;
use App\Exceptions\WorkflowException;
use App\Filament\Pages\CounterPickupBoard;
use App\Filament\Resources\SalesOrderResource;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\InboundPoAlert;
use App\Services\SalesOrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 0.20.4: Counter pickup board logic. Stages (no new order statuses):
 *  to_pick  = Confirmed, board_stage empty      picking = Confirmed, board_stage 'picking'
 *  ready    = Picked                             done    = Delivered/Invoiced today (not POS walk-in sales)
 */
class PickupBoard
{
    public const STAGES = ['to_pick' => 'To pick', 'picking' => 'Picking', 'ready' => 'Ready for pickup', 'done' => 'Picked up today'];

    public const AMBER_HOURS = 2;

    public const RED_HOURS = 24;

    public static function query(?int $warehouseId = null, ?string $search = null): Builder
    {
        $today = now()->startOfDay();
        $q = SalesOrder::query()->with(['customer', 'shipTo', 'warehouse', 'lines.product'])
            ->where(fn (Builder $q) => $q->where('fulfillment', SalesOrder::PICKUP)->orWhereNull('fulfillment'))
            ->where(fn (Builder $q) => $q->whereIn('status', [S::Confirmed->value, S::Picked->value])
                ->orWhere(fn (Builder $q) => $q->whereIn('status', [S::Delivered->value, S::Invoiced->value])
                    ->where('delivered_at', '>=', $today)
                    ->where(fn (Builder $q) => $q->whereNull('channel')->orWhere('channel', '!=', 'pos'))));
        if ($warehouseId) {
            $q->where('warehouse_id', $warehouseId);
        }
        if ($s = trim((string) $search)) {
            $q->where(fn (Builder $q) => $q->where('number', 'like', "%$s%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%$s%")->orWhere('code', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")));
        }

        return $q;
    }

    public static function stage(SalesOrder $o): ?string
    {
        return match ($o->status) {
            S::Confirmed => $o->board_stage === 'picking' ? 'picking' : 'to_pick',
            S::Picked => 'ready',
            S::Delivered, S::Invoiced => 'done',
            default => null,
        };
    }

    /** @return array<string, Collection<int, SalesOrder>> */
    public static function columns(?int $warehouseId = null, ?string $search = null): array
    {
        $all = self::query($warehouseId, $search)->orderByRaw('COALESCE(confirmed_at, created_at)')->orderBy('id')->limit(500)->get();
        $out = array_map(fn () => collect(), self::STAGES);
        foreach ($all as $o) {
            if ($st = self::stage($o)) {
                $out[$st]->push($o);
            }
        }
        $out['done'] = $out['done']->sortByDesc('delivered_at')->values();

        return $out;
    }

    public static function ageMinutes(SalesOrder $o): int
    {
        $from = $o->confirmed_at ?? $o->created_at;

        return $from ? (int) max(0, Carbon::parse($from)->diffInMinutes(now())) : 0;
    }

    public static function ageLabel(SalesOrder $o): string
    {
        $m = self::ageMinutes($o);

        return $m < 60 ? "{$m}m" : ($m < 1440 ? intdiv($m, 60).'h '.($m % 60).'m' : intdiv($m, 1440).'d '.intdiv($m % 1440, 60).'h');
    }

    /** gray / amber (> 2h) / red (> 24h, or past the requested pickup date = ship_date). Done cards are always gray. */
    public static function ageColor(SalesOrder $o): string
    {
        if (self::stage($o) === 'done') {
            return 'gray';
        }
        if ($o->ship_date && $o->ship_date->lt(now()->startOfDay())) {
            return 'red';
        }
        $m = self::ageMinutes($o);

        return $m > self::RED_HOURS * 60 ? 'red' : ($m > self::AMBER_HOURS * 60 ? 'amber' : 'gray');
    }

    public static function needsSignature(SalesOrder $o): bool
    {
        return app(SignatureService::class)->required($o->lines->pluck('product'));
    }

    public static function signed(SalesOrder $o): bool
    {
        return $o->signatures()->exists();
    }

    public static function units(SalesOrder $o): string
    {
        $u = $o->lines->sum(fn ($l) => (float) $l->qty);

        return rtrim(rtrim(number_format($u, 2, '.', ''), '0'), '.');
    }

    public static function phone(SalesOrder $o): ?string
    {
        return $o->shipTo?->phone ?: $o->customer?->phone;
    }

    /** Stage moves the warehouse may make: sales managers and warehouse pickers. */
    public static function canMove(?User $u = null): bool
    {
        $u ??= auth()->user();

        return (bool) $u && ($u->canModule('sales_orders', 'manage') || $u->canModule('warehouse_scan', 'manage'));
    }

    public static function canHandOver(?User $u = null): bool
    {
        return (bool) ($u ?? auth()->user())?->canModule('sales_orders', 'manage');
    }

    public static function canSign(?User $u = null): bool
    {
        return (bool) ($u ?? auth()->user())?->canModule('pickup_signature', 'manage');
    }

    private static function log(SalesOrder $o, string $from, string $to): void
    {
        Audit::action('pickup_board_stage', $o, "{$o->number}: ".self::STAGES[$from].' → '.self::STAGES[$to], ['stage' => $to], ['stage' => $from]);
    }

    public static function startPicking(SalesOrder $o): SalesOrder
    {
        if (self::stage($o) !== 'to_pick') {
            throw new WorkflowException("{$o->number} is not waiting to be picked.");
        }
        $o->update(['board_stage' => 'picking', 'board_stage_at' => now(), 'board_stage_by' => auth()->id()]);
        self::log($o, 'to_pick', 'picking');

        return $o;
    }

    /** Back to To pick (picker put it down). */
    public static function unstart(SalesOrder $o): SalesOrder
    {
        if (self::stage($o) !== 'picking') {
            throw new WorkflowException("{$o->number} is not being picked.");
        }
        $o->update(['board_stage' => null, 'board_stage_at' => now(), 'board_stage_by' => auth()->id()]);
        self::log($o, 'picking', 'to_pick');

        return $o;
    }

    public static function markReady(SalesOrder $o): SalesOrder
    {
        $from = self::stage($o);
        if (! in_array($from, ['to_pick', 'picking'], true)) {
            throw new WorkflowException("{$o->number} cannot be marked ready.");
        }
        app(SalesOrderService::class)->pick($o);
        $o->update(['board_stage' => 'ready', 'board_stage_at' => now(), 'board_stage_by' => auth()->id()]);
        self::log($o, $from, 'ready');

        return $o;
    }

    /** Hand-over: deliver (auto-invoices). SalesOrderService enforces the signature rule for regulated items. */
    public static function pickedUp(SalesOrder $o): SalesOrder
    {
        $from = self::stage($o);
        $o = app(SalesOrderService::class)->deliver($o);
        $o->update(['board_stage' => 'done', 'board_stage_at' => now(), 'board_stage_by' => auth()->id()]);
        self::log($o, $from ?? 'ready', 'done');

        return $o;
    }

    public static function afterCollect(SalesOrder $o, string $from): void
    {
        $o->refresh();
        $o->update(['board_stage' => 'done', 'board_stage_at' => now(), 'board_stage_by' => auth()->id()]);
        self::log($o, $from, 'done');
    }

    /** Bell recipients: the warehouse's receiving team, else active Warehouse-role users. No owner fallback (avoid noise). */
    public static function recipients(?Warehouse $wh): Collection
    {
        $users = $wh ? $wh->receivers()->where('active', true)->get() : collect();
        if ($users->isEmpty()) {
            $users = User::where('active', true)->where('role', Role::Warehouse->value)->get();
        }

        return $users->filter(fn (User $u) => $u->canModule('warehouse_scan', 'view') || $u->canModule('sales_orders', 'view'))->values();
    }

    /** Model hook: a pickup order just became Confirmed (not POS walk-in). Never throws. */
    public static function onSaved(SalesOrder $o): void
    {
        try {
            if (! $o->wasChanged('status') && ! $o->wasRecentlyCreated) {
                return;
            }
            if ($o->status !== S::Confirmed || ! $o->isPickup() || $o->channel === 'pos') {
                return;
            }
            $users = self::recipients($o->warehouse);
            if ($users->isEmpty()) {
                return;
            }
            $o->loadMissing('customer', 'lines');
            $title = "Counter pickup: {$o->number} to pick";
            $body = ($o->customer?->name ?? 'Customer').' · '.$o->lines->count().' items · $'.number_format((float) $o->total, 2);
            $n = new InboundPoAlert($title, $body, CounterPickupBoard::getUrl());
            foreach ($users as $u) {
                $u->notify($n);
            }
            Audit::action('pickup_board_alert', $o, $title.' → '.$users->pluck('name')->implode(', '), ['recipients' => $users->pluck('id')->all()]);
        } catch (Throwable $e) {
            Log::warning("Pickup board alert for {$o->number} failed: ".$e->getMessage());
        }
    }

    public static function orderUrl(SalesOrder $o): string
    {
        return SalesOrderResource::getUrl('edit', ['record' => $o]);
    }
}
