<?php

namespace App\Filament\Pages;

use App\Enums\SalesOrderStatus as S;
use App\Filament\Resources\SalesOrderResource;
use App\Filament\Support\OrderActions;
use App\Models\Customer;
use App\Models\SalesOrder;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** 0.20: everything delivered but not paid: delivered-not-invoiced + invoiced with a balance, aged from invoice (or delivery) date. */
class Receivables extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'filament.pages.table-page';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?string $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $title = 'Receivables';

    protected static ?string $slug = 'sales/receivables';

    protected static ?int $navigationSort = 3;

    public const BUCKETS = ['current' => 'Current (0-29 days)', '30' => '30-59 days', '60' => '60-89 days', '90' => '90+ days'];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canModule('sales_orders', 'view');
    }

    public function intro(): string
    {
        return 'Orders delivered but not paid. Age counts from the invoice date (delivery date if not invoiced yet). '
            .'Use <strong>Record payment</strong> on a row (it invoices first when needed), or select orders and <strong>Invoice selected</strong>.';
    }

    public static function query(): Builder
    {
        return SalesOrder::query()->with('customer')->whereIn('status', [S::Delivered->value, S::Invoiced->value])
            ->whereRaw('total - (amount_paid + amount_credited) > 0.004');
    }

    public static function ageDays(SalesOrder $r): int
    {
        $d = $r->invoiced_at ?? $r->delivered_at ?? $r->order_date;

        return $d ? (int) max(0, \Illuminate\Support\Carbon::parse($d)->startOfDay()->diffInDays(now()->startOfDay())) : 0;
    }

    public static function bucket(SalesOrder $r): string
    {
        $d = self::ageDays($r);

        return $d >= 90 ? '90' : ($d >= 60 ? '60' : ($d >= 30 ? '30' : 'current'));
    }

    public function table(Table $table): Table
    {
        return $table->query(self::query())->defaultSort('id', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('number')->searchable()
                    ->url(fn (SalesOrder $r) => SalesOrderResource::getUrl('edit', ['record' => $r])),
                Tables\Columns\TextColumn::make('customer.name')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('progress')->label('Progress')->html()->getStateUsing(fn (SalesOrder $r) => $r->stageChips()),
                Tables\Columns\TextColumn::make('age')->label('Days')->getStateUsing(fn (SalesOrder $r) => self::ageDays($r)),
                Tables\Columns\TextColumn::make('bucket')->label('Aging')->badge()
                    ->getStateUsing(fn (SalesOrder $r) => self::BUCKETS[self::bucket($r)])
                    ->color(fn (SalesOrder $r) => ['current' => 'gray', '30' => 'info', '60' => 'warning', '90' => 'danger'][self::bucket($r)]),
                Tables\Columns\TextColumn::make('total')->money('USD'),
                Tables\Columns\TextColumn::make('balance_due')->label('Balance')->money('USD')->getStateUsing(fn (SalesOrder $r) => $r->balance()),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('customer_id')->label('Customer')->searchable()
                    ->relationship('customer', 'name'),
                Tables\Filters\SelectFilter::make('status')->label('Stage')->options(['delivered' => 'Delivered, not invoiced', 'invoiced' => 'Invoiced, unpaid']),
                Tables\Filters\SelectFilter::make('aging')->label('Aging')->options(self::BUCKETS)
                    ->query(function (Builder $query, array $data) {
                        $b = $data['value'] ?? null;
                        if (! $b) {
                            return $query;
                        }
                        [$min, $max] = ['current' => [0, 29], '30' => [30, 59], '60' => [60, 89], '90' => [90, null]][$b];
                        $date = 'COALESCE(invoiced_at, delivered_at, order_date)';
                        $query->whereRaw("$date <= ?", [now()->subDays($min)->endOfDay()]);
                        if ($max !== null) {
                            $query->whereRaw("$date > ?", [now()->subDays($max + 1)->endOfDay()]);
                        }

                        return $query;
                    }),
            ])
            ->actions([
                OrderActions::pay(Tables\Actions\Action::class),
                OrderActions::chargeCard(Tables\Actions\Action::class),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('bulkInvoice')->label('Invoice selected')->icon('heroicon-o-document-text')->requiresConfirmation()
                    ->visible(fn () => OrderActions::canManage())
                    ->action(fn (\Illuminate\Support\Collection $records) => SalesOrderResource::bulkInvoice($records)),
            ]);
    }
}
