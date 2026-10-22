<?php

namespace App\Filament\Support;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Purchasing\InboundTracking;
use App\Purchasing\ReceivingAlerts;
use Filament\Actions\Action as PageAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * 0.20.2: "Update tracking" — edits ONLY the inbound shipping fields of a PO
 * (expected arrival, carrier, carrier name, tracking numbers, custom tracking URL).
 * Allowed: owners/admins, roles in erp.permissions.purchase_orders.update_tracking, and the PO warehouse's receiving team.
 * Saves through the model, so the Activity log and the receiving-team alert still fire.
 */
class UpdateTrackingAction
{
    public const FIELDS = ReceivingAlerts::WATCH;

    public static function allowed(?User $u, PurchaseOrder $po): bool
    {
        if (! $u || ! $u->active) {
            return false;
        }
        if ($u->canModule('purchase_orders', 'update_tracking')) {
            return true;
        }

        return (bool) $po->warehouse?->receivers()->whereKey($u->id)->exists();
    }

    public static function open(PurchaseOrder $po): bool
    {
        return ! in_array($po->status, ['received', 'cancelled', 'closed'], true);
    }

    public static function schema(): array
    {
        return [
            Forms\Components\DatePicker::make('expected_at')->label('Expected arrival'),
            Forms\Components\Select::make('carrier_code')->label('Carrier')->options(InboundTracking::CARRIERS)->live(),
            Forms\Components\TextInput::make('carrier_name')->label('Carrier name')->maxLength(80)
                ->visible(fn (Forms\Get $get) => $get('carrier_code') === 'other')->placeholder('e.g. Estes, R+L, own truck'),
            Forms\Components\TagsInput::make('tracking_numbers')->label('Tracking number(s)')->placeholder('Add a number, press Enter')->splitKeys([',', ' ', 'Enter']),
            Forms\Components\TextInput::make('tracking_url')->label('Custom tracking URL')->url()->maxLength(500)
                ->helperText('Optional. Overrides the carrier link; use {tracking} where the number goes.'),
        ];
    }

    public static function fill(PurchaseOrder $po): array
    {
        return [
            'expected_at' => $po->expected_at?->toDateString(),
            'carrier_code' => $po->carrier_code,
            'carrier_name' => $po->carrier_name,
            'tracking_numbers' => $po->tracking_numbers ?? [],
            'tracking_url' => $po->tracking_url,
        ];
    }

    /** Applies only whitelisted fields; anything else in $data is ignored. */
    public static function apply(PurchaseOrder $po, array $data, ?User $u = null): PurchaseOrder
    {
        $u ??= auth()->user();
        if (! self::allowed($u, $po)) {
            throw new AuthorizationException('You cannot update tracking on this purchase order.');
        }
        $d = array_intersect_key($data, array_flip(self::FIELDS));
        if (array_key_exists('tracking_numbers', $d)) {
            $d['tracking_numbers'] = array_values(array_filter(array_map(fn ($t) => trim((string) $t), (array) $d['tracking_numbers']), 'strlen')) ?: null;
        }
        if (array_key_exists('carrier_code', $d) && $d['carrier_code'] !== 'other') {
            $d['carrier_name'] = null;
        }
        foreach ($d as $k => $v) {
            $po->{$k} = $v === '' ? null : $v;
        }
        $po->save();

        return $po;
    }

    private static function configure($a)
    {
        return $a->label('Update tracking')->icon('heroicon-o-truck')
            ->modalHeading(fn (PurchaseOrder $record) => "Inbound shipping: PO {$record->number}")
            ->modalDescription('Only the inbound shipping details can be changed here. The receiving team is alerted about the change.')
            ->visible(fn (PurchaseOrder $record) => self::open($record) && self::allowed(auth()->user(), $record))
            ->fillForm(fn (PurchaseOrder $record) => self::fill($record))
            ->form(self::schema())
            ->action(function (PurchaseOrder $record, array $data) {
                self::apply($record, $data);
                Notification::make()->success()->title("PO {$record->number}: tracking updated")->send();
            });
    }

    public static function table(): TableAction
    {
        return self::configure(TableAction::make('updateTracking'));
    }

    public static function page(): PageAction
    {
        return self::configure(PageAction::make('updateTracking'));
    }
}
