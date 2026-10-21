<?php

namespace App\Filament\Pages;

use App\Models\Buydown;
use App\Models\BuydownAccrual;
use App\Pricing\RebateClaimService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Buydown / manufacturer rebate claim report: accruals per invoiced line, CSV for the manufacturer, mark claimed. */
class RebateClaims extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationGroup = 'Pricing';

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Rebate & buydown claims';

    protected static string $view = 'filament.pages.rebate-claims';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canModule('rebate_claims');
    }

    public function table(Table $table): Table
    {
        return $table->query(BuydownAccrual::query()->with(['buydown', 'product', 'customer', 'salesOrder']))
            ->defaultSort('sold_on', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('buydown.manufacturer')->label('Manufacturer')->searchable(),
                Tables\Columns\TextColumn::make('buydown.kind')->label('Type')->badge(),
                Tables\Columns\TextColumn::make('buydown.program_code')->label('Program')->placeholder('-'),
                Tables\Columns\TextColumn::make('kind')->label('Line')->badge()->color(fn ($state) => $state === 'reversal' ? 'danger' : 'success'),
                Tables\Columns\TextColumn::make('salesOrder.number')->label('Invoice')->searchable(),
                Tables\Columns\TextColumn::make('creditMemo.number')->label('Credit memo')->placeholder('-'),
                Tables\Columns\TextColumn::make('sold_on')->date()->sortable(),
                Tables\Columns\TextColumn::make('customer.name')->wrap(),
                Tables\Columns\TextColumn::make('product.sku')->label('SKU'),
                Tables\Columns\TextColumn::make('qty')->numeric(2),
                Tables\Columns\TextColumn::make('amount_per_unit')->money('USD')->label('Per unit'),
                Tables\Columns\TextColumn::make('amount')->money('USD')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('USD')),
                Tables\Columns\TextColumn::make('claimed_at')->date()->placeholder('unclaimed'),
                Tables\Columns\TextColumn::make('claim_ref')->placeholder('-'),
                Tables\Columns\TextColumn::make('paid_at')->date()->label('Paid')->placeholder('-'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('manufacturer')->options(fn () => Buydown::query()->distinct()->orderBy('manufacturer')->pluck('manufacturer', 'manufacturer'))
                    ->query(fn (Builder $q, array $data) => filled($data['value'] ?? null) ? $q->whereHas('buydown', fn ($b) => $b->where('manufacturer', $data['value'])) : $q),
                Tables\Filters\TernaryFilter::make('claimed')->nullable()->attribute('claimed_at')->default(false)
                    ->trueLabel('Claimed')->falseLabel('Unclaimed'),
                Tables\Filters\Filter::make('sold')->form([Forms\Components\DatePicker::make('from'), Forms\Components\DatePicker::make('to')])
                    ->query(fn (Builder $q, array $data) => $q->when($data['from'] ?? null, fn ($w, $v) => $w->whereDate('sold_on', '>=', $v))->when($data['to'] ?? null, fn ($w, $v) => $w->whereDate('sold_on', '<=', $v))),
            ])
            ->headerActions([
                Tables\Actions\Action::make('csv')->label('Download claim CSV')->icon('heroicon-o-arrow-down-tray')
                    ->action(function () {
                        $csv = app(RebateClaimService::class)->csv($this->getFilteredTableQuery()->clone());

                        return response()->streamDownload(fn () => print ($csv), 'rebate-claims-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('markClaimed')->label('Mark claimed')->icon('heroicon-o-check')
                    ->visible(fn () => auth()->user()->canModule('rebate_claims', 'manage'))
                    ->form([Forms\Components\TextInput::make('claim_ref')->label('Claim reference')->required()->maxLength(60)])
                    ->action(function (Collection $records, array $data) {
                        $n = app(RebateClaimService::class)->markClaimed($records, $data['claim_ref']);
                        Notification::make()->success()->title("$n line(s) marked claimed")->send();
                    })->deselectRecordsAfterCompletion(),
                Tables\Actions\BulkAction::make('recordPayment')->label('Record manufacturer payment')->icon('heroicon-o-banknotes')
                    ->visible(fn () => auth()->user()->canModule('rebate_claims', 'manage'))
                    ->form(fn (Collection $records) => [
                        Forms\Components\DatePicker::make('date')->default(now()->toDateString())->required(),
                        Forms\Components\TextInput::make('received')->label('Amount received')->numeric()->required()
                            ->default(fn () => number_format((float) $records->filter(fn ($r) => $r->claimed_at && ! $r->paid_at)->sum('amount'), 2, '.', ''))
                            ->helperText('Dr Cash / Cr 1250 for the claimed lines; any difference posts to 5050.'),
                        Forms\Components\TextInput::make('reference')->maxLength(60),
                    ])
                    ->action(function (Collection $records, array $data) {
                        try {
                            $j = app(\App\Pricing\RebateLedger::class)->recordPayment($records, $data['date'], $data['received'], $data['reference'] ?? null);
                            Notification::make()->success()->title("Payment posted (journal #{$j->id})")->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Not posted')->body($e->getMessage())->persistent()->send();
                        }
                    })->deselectRecordsAfterCompletion(),
            ]);
    }
}
