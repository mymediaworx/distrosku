<?php

namespace App\Purchasing;

use App\Audit\Audit;
use App\Enums\Role;
use App\Filament\Pages\ExpectedInbound;
use App\Filament\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\InboundPoAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 0.20.1: who receives at a warehouse and what they are told.
 * Recipients = the warehouse's Receiving users (Warehouses > Receiving team). If none are set: every active Warehouse-role user.
 * If there are none of those either: active owners/admins, so an alert is never silently dropped.
 */
class ReceivingAlerts
{
    public const OPEN = ['ordered', 'partial'];

    public const WATCH = ['expected_at', 'carrier_code', 'carrier_name', 'tracking_numbers', 'tracking_url'];

    public static function recipients(?Warehouse $wh): Collection
    {
        $users = $wh ? $wh->receivers()->where('active', true)->get() : collect();
        if ($users->isNotEmpty()) {
            return $users->values(); // chosen explicitly per warehouse
        }
        if ($users->isEmpty()) {
            $users = User::where('active', true)->where('role', Role::Warehouse->value)->get();
        }
        if ($users->isEmpty()) {
            $users = User::where('active', true)->whereIn('role', [Role::Owner->value, Role::Admin->value])->get();
        }

        return $users->filter(fn (User $u) => $u->canModule('purchase_orders', 'receive') || $u->isAdmin())->values();
    }

    /** Model hook (PurchaseOrder saved). Never throws: an alert failure must not block saving the PO. */
    public static function onSaved(PurchaseOrder $po): void
    {
        try {
            $statusNowOrdered = $po->status === 'ordered' && ($po->wasRecentlyCreated || $po->wasChanged('status'));
            if ($statusNowOrdered) {
                if (! $po->ordered_at) {
                    $po->ordered_at = now();
                    $po->saveQuietly();
                }
                self::send($po, 'sent');

                return;
            }
            if (! $po->wasRecentlyCreated && in_array($po->status, self::OPEN, true) && $po->wasChanged(self::WATCH)) {
                self::send($po, 'updated', array_keys(array_intersect_key($po->getChanges(), array_flip(self::WATCH))));
            }
        } catch (Throwable $e) {
            Log::warning("Receiving alert for PO {$po->number} failed: ".$e->getMessage());
        }
    }

    public static function summary(PurchaseOrder $po): string
    {
        $po->loadMissing('vendor', 'warehouse');
        $bits = [($po->vendor?->name ?? 'Vendor').' → '.($po->warehouse?->code ?? '?')];
        $bits[] = 'ETA '.($po->expected_at ? $po->expected_at->format('D M j') : 'not set');
        if ($c = InboundTracking::carrierLabel($po)) {
            $bits[] = $c;
        }
        if ($n = InboundTracking::numbers($po)) {
            $bits[] = 'Tracking '.implode(', ', $n);
        }

        return implode(' · ', $bits);
    }

    public static function send(PurchaseOrder $po, string $kind, array $changed = []): int
    {
        $users = self::recipients($po->warehouse);
        if ($users->isEmpty()) {
            return 0;
        }
        $title = match ($kind) {
            'sent' => "Inbound: PO {$po->number} sent to vendor",
            default => "Inbound: PO {$po->number} ".(in_array('expected_at', $changed, true) ? 'ETA' : 'tracking').' updated',
        };
        $n = new InboundPoAlert($title, self::summary($po), PurchaseOrderResource::getUrl('edit', ['record' => $po]), InboundTracking::links($po));
        foreach ($users as $u) {
            $u->notify($n);
        }
        Audit::action('po_receiving_alert', $po, $title.' → '.$users->pluck('name')->implode(', '), ['kind' => $kind, 'changed' => $changed, 'recipients' => $users->pluck('id')->all()]);

        return $users->count();
    }

    /** Open POs grouped for the digest/page. */
    public static function bucket(PurchaseOrder $po): string
    {
        if (! $po->expected_at) {
            return 'unscheduled';
        }
        $d = $po->expected_at->copy()->startOfDay();
        $today = now()->startOfDay();

        return match (true) {
            $d->lt($today) => 'overdue',
            $d->eq($today) => 'today',
            $d->eq($today->copy()->addDay()) => 'tomorrow',
            $d->lte($today->copy()->endOfWeek()) => 'week',
            default => 'later',
        };
    }

    /** Morning digest per warehouse: overdue, today, tomorrow. Returns number of notifications sent. */
    public static function digest(): int
    {
        $pos = PurchaseOrder::with('vendor', 'warehouse')->whereIn('status', self::OPEN)->whereNotNull('expected_at')
            ->whereDate('expected_at', '<=', now()->addDay()->toDateString())->orderBy('expected_at')->get();
        $sent = 0;
        foreach ($pos->groupBy('warehouse_id') as $group) {
            $wh = $group->first()->warehouse;
            $b = $group->groupBy(fn ($po) => self::bucket($po));
            $line = fn ($k, $label) => ($b[$k] ?? collect())->isEmpty() ? null
                : $label.': '.($b[$k])->map(fn ($po) => $po->number.' ('.($po->vendor?->name ?? '').')')->implode(', ');
            $body = implode('<br>', array_filter([$line('overdue', 'Overdue'), $line('today', 'Today'), $line('tomorrow', 'Tomorrow')]));
            $title = 'Expected inbound '.($wh?->code ?? '').': '.($b['today'] ?? collect())->count().' today, '.($b['tomorrow'] ?? collect())->count().' tomorrow, '.($b['overdue'] ?? collect())->count().' overdue';
            $users = self::recipients($wh);
            $n = new InboundPoAlert($title, $body, ExpectedInbound::getUrl(), [], ($b['overdue'] ?? collect())->isNotEmpty() ? 'warning' : 'info');
            foreach ($users as $u) {
                $u->notify($n);
                $sent++;
            }
            if ($users->isNotEmpty()) {
                Audit::action('po_receiving_digest', $wh, $title, ['pos' => $group->pluck('number')->all(), 'recipients' => $users->pluck('id')->all()]);
            }
        }

        return $sent;
    }
}
