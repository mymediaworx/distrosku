<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use App\Purchasing\InboundTracking;
use App\Purchasing\ReceivingAlerts;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** 0.20.1: what the receiving team should expect: open POs by expected date with outstanding qty and Track links. */
class ExpectedInbound extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'filament.pages.table-page';

    protected static ?string $navigationGroup = 'Purchasing';

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $title = 'Expected inbound';

    protected static ?string $slug = 'purchasing/expected-inbound';

    protected static ?int $navigationSort = 3;

    public const BUCKETS = ['overdue' => 'Overdue', 'today' => 'Today', 'tomorrow' => 'Tomorrow', 'week' => 'This week', 'later' => 'Later', 'unscheduled' => 'No ETA'];

    public const COLORS = ['overdue' => 'danger', 'today' => 'warning', 'tomorrow' => 'info', 'week' => 'info', 'later' => 'gray', 'unscheduled' => 'gray'];

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) $u && ($u->canModule('purchase_orders', 'receive') || $u->canModule('purchase_orders', 'view'));
    }

    public function intro(): string
    {
        return 'Open purchase orders sent to vendors (Ordered / Partial), soonest first. The receiving team of each warehouse gets a bell alert when a PO is sent, '
            .'when its ETA or tracking changes, and a morning digest of POs due today, tomorrow and overdue.';
    }

    public static function query(): Builder
    {
        return PurchaseOrder::query()->with('vendor', 'warehouse', 'lines.product')->whereIn('status', ReceivingAlerts::OPEN);
    }

    public static function outstanding(PurchaseOrder $po): string
    {
        return $po->lines->filter(fn ($l) => bccomp((string) $l->qty, (string) $l->qty_received, 4) > 0)
            ->map(fn ($l) => e(($l->product?->sku ?? '?').' '.($l->product?->name ?? '')).' × '.rtrim(rtrim(bcsub((string) $l->qty, (string) $l->qty_received, 4), '0'), '.'))
            ->implode('<br>');
    }

    public function table(Table $table): Table
    {
        return $table->query(self::query())
            ->defaultSort(fn (Builder $q) => $q->orderByRaw('expected_at IS NULL')->orderBy('expected_at')->orderBy('id'))
            ->columns([
                Tables\Columns\TextColumn::make('number')->searchable()
                    ->url(fn (PurchaseOrder $r) => PurchaseOrderResource::getUrl(PurchaseOrderResource::canEdit($r) ? 'edit' : 'view', ['record' => $r])),
                Tables\Columns\TextColumn::make('when')->label('Due')->badge()
                    ->getStateUsing(fn (PurchaseOrder $r) => self::BUCKETS[ReceivingAlerts::bucket($r)])
                    ->color(fn (PurchaseOrder $r) => self::COLORS[ReceivingAlerts::bucket($r)]),
                Tables\Columns\TextColumn::make('expected_at')->label('Expected')->date(),
                Tables\Columns\TextColumn::make('vendor.name')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('warehouse.code')->label('WH'),
                Tables\Columns\TextColumn::make('outstanding')->label('Products outstanding')->html()->wrap()
                    ->getStateUsing(fn (PurchaseOrder $r) => self::outstanding($r)),
                Tables\Columns\TextColumn::make('carrier')->label('Carrier')->getStateUsing(fn (PurchaseOrder $r) => InboundTracking::carrierLabel($r)),
                Tables\Columns\TextColumn::make('track')->label('Tracking')->html()->getStateUsing(fn (PurchaseOrder $r) => InboundTracking::html($r)),
                Tables\Columns\TextColumn::make('status')->badge(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('due')->label('Due')->options(self::BUCKETS)
                    ->query(function (Builder $q, array $data) {
                        $b = $data['value'] ?? null;
                        $t = now()->toDateString();

                        return match ($b) {
                            'overdue' => $q->whereDate('expected_at', '<', $t),
                            'today' => $q->whereDate('expected_at', $t),
                            'tomorrow' => $q->whereDate('expected_at', now()->addDay()->toDateString()),
                            'week' => $q->whereDate('expected_at', '>', now()->addDay()->toDateString())->whereDate('expected_at', '<=', now()->endOfWeek()->toDateString()),
                            'later' => $q->whereDate('expected_at', '>', now()->endOfWeek()->toDateString()),
                            'unscheduled' => $q->whereNull('expected_at'),
                            default => $q,
                        };
                    }),
                Tables\Filters\SelectFilter::make('warehouse_id')->label('Warehouse')->options(fn () => Warehouse::orderBy('code')->pluck('code', 'id')),
            ])
            ->actions([
                \App\Filament\Support\UpdateTrackingAction::table(),
                Tables\Actions\Action::make('startReceiving')->label('Start receiving')->icon('heroicon-o-qr-code')->button()
                    ->visible(fn () => auth()->user()->canModule('warehouse_scan', 'manage'))
                    ->url(fn (PurchaseOrder $r) => route('wh.receive', $r)),
                Tables\Actions\Action::make('openPo')->label('Open PO')->icon('heroicon-o-inbox-arrow-down')
                    ->visible(fn () => ! auth()->user()->canModule('warehouse_scan', 'manage'))
                    ->url(fn (PurchaseOrder $r) => PurchaseOrderResource::getUrl(PurchaseOrderResource::canEdit($r) ? 'edit' : 'view', ['record' => $r])),
            ]);
    }
}
