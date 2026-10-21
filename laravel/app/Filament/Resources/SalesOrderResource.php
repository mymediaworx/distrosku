<?php

namespace App\Filament\Resources;

use App\Credit\CreditLimit;
use App\Enums\SalesOrderStatus as S;
use App\Filament\Concerns\RoleGated;
use App\Filament\Pages\CardPayments;
use App\Filament\RelationManagers\HistoryRelationManager;
use App\Filament\Resources\SalesOrderResource\Pages;
use App\Models\Customer;
use App\Models\PickList;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\ShipTo;
use App\Models\Warehouse;
use App\Pricing\PricingEngine;
use App\Services\ComplianceChecker;
use App\Services\CreditMemoService;
use App\Services\SalesOrderService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Throwable;

class SalesOrderResource extends Resource
{
    use RoleGated;

    protected static string $module = 'sales_orders';

    protected static ?string $model = SalesOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'number';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Signatures / proof of delivery')->collapsible()
                ->visible(fn ($record) => $record && $record->signatures()->exists())
                ->schema([Forms\Components\Placeholder::make('sigs')->hiddenLabel()
                    ->content(fn ($record) => new HtmlString(view('filament.partials.order-signatures', ['so' => $record->load('signatures.user')])->render()))]),
            Forms\Components\Section::make()->columns(['default' => 1, 'md' => 3])->schema([
                Forms\Components\TextInput::make('number')->disabled()->dehydrated(false)->placeholder('Assigned on save'),
                Forms\Components\Select::make('customer_id')->relationship('customer', 'name')->searchable()->preload()->required()->live()
                    ->afterStateUpdated(fn (Set $set, $state) => $set('ship_to_id', $state ? \App\Models\Customer::find($state)?->defaultShipTo()?->id : null)), // 0.19.3: default ship-to
                Forms\Components\Placeholder::make('available_credit')->label('Available credit')
                    ->visible(fn (Get $get) => filled($get('customer_id')))
                    ->content(fn (Get $get) => ($c = Customer::find($get('customer_id'))) ? CreditLimit::summary($c) : ''),
                Forms\Components\Select::make('ship_to_id')->label('Ship-to')->required()
                    ->options(fn (Get $get) => ShipTo::where('customer_id', $get('customer_id'))->orderByDesc('is_default')->orderBy('id')->get()->mapWithKeys(fn ($s) => [$s->id => "{$s->name} ({$s->state})"])),
                Forms\Components\Select::make('sales_rep_id')->label('Sales rep')->relationship('salesRep', 'name')->searchable()->preload()->nullable()->helperText('Blank = customer\'s rep'),
                Forms\Components\Select::make('warehouse_id')->relationship('warehouse', 'name')->required()->default(fn () => Warehouse::where('code', 'MAIN')->value('id') ?? Warehouse::value('id')),
                Forms\Components\DatePicker::make('order_date')->default(now())->required(),
                Forms\Components\DatePicker::make('ship_date')->label('Ship / delivery date')->helperText('Optional. Eligibility rules are checked on the order date and this date.'),
                Forms\Components\Placeholder::make('elig_warnings')->label('Eligibility warnings')
                    ->visible(fn (?SalesOrder $record) => filled($record?->elig_warnings))
                    ->content(fn (?SalesOrder $record) => collect($record?->elig_warnings ?? [])->map(fn ($m, $sku) => $sku.': '.implode(' ', $m))->implode(' | ')),
                Forms\Components\Placeholder::make('status_display')->label('Status')->content(fn (?SalesOrder $record) => ucfirst($record?->status?->value ?? 'draft')),
            ]),
            Forms\Components\Repeater::make('lines')->relationship()->columns(['default' => 2, 'md' => 4])
                ->disabled(fn (?SalesOrder $record) => $record && $record->status !== S::Draft)
                ->schema([
                    Forms\Components\Hidden::make('quoted')->dehydrated(false)
                        ->afterStateHydrated(fn (Set $set, ?SalesOrderLine $record) => $set('quoted', $record?->quoted_price)),
                    Forms\Components\Select::make('product_id')->label('Product')->required()->searchable()
                        ->rule(fn () => function ($attr, $value, $fail) {
                            if (($p = Product::find($value)) && $p->isArchived()) {
                                $fail("Product {$p->sku} is archived and can't be added to new orders.");
                            }
                        })
                        ->getSearchResultsUsing(fn (string $search) => Product::where('active', true)->notArchived()->where(fn ($w) => $w->where('sku', 'like', "%$search%")->orWhere('upc', 'like', "%$search%")->orWhere('name', 'like', "%$search%"))->limit(30)->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} {$p->name}"]))
                        ->getOptionLabelUsing(fn ($value) => ($p = Product::find($value)) ? "{$p->sku} {$p->name}" : null)
                        ->live()->afterStateUpdated(fn (Get $get, Set $set) => self::fillQuote($get, $set))
                        ->columnSpan(['default' => 2, 'md' => 2]),
                    Forms\Components\TextInput::make('qty')->numeric()->default(1)->required()->minValue(0.0001)
                        ->live(onBlur: true)->afterStateUpdated(fn (Get $get, Set $set) => self::fillQuote($get, $set, true)),
                    Forms\Components\TextInput::make('unit_price')->numeric()->required()->prefix('$')
                        ->helperText(fn (Get $get, ?SalesOrderLine $record) => self::sourceHint($get, $record)),
                ])->defaultItems(1)->columnSpanFull(),
            Forms\Components\Textarea::make('notes')->columnSpanFull(),
            Forms\Components\Placeholder::make('compliance')->label('Compliance blocks')->columnSpanFull()
                ->visible(fn (?SalesOrder $record) => ! empty($record?->compliance_reasons))
                ->content(fn (?SalesOrder $record) => collect($record?->compliance_reasons ?? [])->map(fn ($r, $sku) => "$sku: ".implode(' ', $r))->implode(' | ')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('number')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('customer.name')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('shipTo.state')->label('St'),
                Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => ucfirst($state->value))
                    ->color(fn ($state) => match ($state) {
                        S::Invoiced => 'success', S::Cancelled => 'gray', S::Draft => 'warning', default => 'info'
                    }),
                Tables\Columns\TextColumn::make('total')->money('USD')->sortable(),
                Tables\Columns\TextColumn::make('order_date')->date()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('channel')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->options(S::options())])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    self::step('check', 'Check compliance', 'heroicon-o-shield-check', [S::Draft], function (SalesOrder $r) {
                        $res = app(ComplianceChecker::class)->checkOrder($r);
                        $r->update(['compliance_reasons' => $res ?: null]);

                        return $res ? 'Blocked: '.collect($res)->map(fn ($x, $k) => "$k: ".implode(' ', $x))->implode(' | ') : 'All lines pass.';
                    }),
                    self::step('confirm', 'Confirm', 'heroicon-o-check', [S::Draft], fn ($r) => [app(SalesOrderService::class)->confirm($r), 'Confirmed and stock reserved.'][1]),
                    Tables\Actions\Action::make('creditOverride')->label('Confirm with credit override')->icon('heroicon-o-shield-exclamation')->color('warning')
                        ->visible(fn (SalesOrder $r) => $r->status === S::Draft && auth()->user()->isAdmin() && app(CreditLimit::class)->limit($r->customer) !== null)
                        ->modalDescription(fn (SalesOrder $r) => CreditLimit::summary($r->customer).'. The override and reason are recorded in the Activity log.')
                        ->form([Forms\Components\Textarea::make('reason')->label('Reason for override')->required()->minLength(3)->maxLength(500)])
                        ->action(function (SalesOrder $r, array $data) {
                            try {
                                app(CreditLimit::class)->override($r, auth()->user(), $data['reason']);
                                app(SalesOrderService::class)->confirm($r->fresh());
                                Notification::make()->success()->title('Confirmed with credit override.')->send();
                            } catch (Throwable $e) {
                                Notification::make()->danger()->title($e->getMessage())->send();
                            }
                        }),
                    Tables\Actions\Action::make('eligOverride')->label('Confirm with eligibility override')->icon('heroicon-o-shield-exclamation')->color('danger')
                        ->visible(fn (SalesOrder $r) => $r->status === S::Draft && auth()->user()->isAdmin() && filled($r->compliance_reasons))
                        ->modalDescription(fn (SalesOrder $r) => 'Product eligibility blocks: '.collect(app(\App\Compliance\Eligibility::class)->checkOrder($r)['block'])
                            ->map(fn ($m, $sku) => $sku.': '.implode(' ', $m))->implode(' | ').'. Only eligibility-registry blocks are overridden (PACT, licenses and stock still apply). The override and reason are recorded in the Activity log.')
                        ->form([Forms\Components\Textarea::make('reason')->label('Reason for override')->required()->minLength(3)->maxLength(500)])
                        ->action(function (SalesOrder $r, array $data) {
                            try {
                                app(\App\Compliance\Eligibility::class)->override($r, auth()->user(), $data['reason']);
                                app(SalesOrderService::class)->confirm($r->fresh());
                                Notification::make()->success()->title('Confirmed with eligibility override.')->send();
                            } catch (Throwable $e) {
                                Notification::make()->danger()->title($e->getMessage())->send();
                            }
                        }),
                    self::step('pick', 'Mark picked', 'heroicon-o-archive-box', [S::Confirmed], fn ($r) => [app(SalesOrderService::class)->pick($r), 'Picked.'][1]),
                    self::step('deliver', 'Deliver', 'heroicon-o-truck', [S::Confirmed, S::Picked], fn ($r) => [app(SalesOrderService::class)->deliver($r), 'Delivered; stock decreased.'][1]),
                    Tables\Actions\Action::make('pickupSign')->label('Pickup (signature)')->icon('heroicon-o-pencil-square')
                        ->visible(fn (SalesOrder $r) => in_array($r->status, [S::Confirmed, S::Picked], true) && auth()->user()->canModule('pickup_signature', 'manage'))
                        ->url(fn (SalesOrder $r) => route('driver.pickup', $r)),
                    Tables\Actions\Action::make('deliveryProof')->label('Delivery receipt PDF')->icon('heroicon-o-document-check')
                        ->visible(fn (SalesOrder $r) => $r->signatures()->exists() || $r->deliveryStops()->where('status', '!=', 'pending')->exists())
                        ->url(fn (SalesOrder $r) => route('docs.delivery-proof', $r)),
                    self::step('invoice', 'Invoice', 'heroicon-o-document-text', [S::Delivered], fn ($r) => [app(SalesOrderService::class)->invoice($r), 'Invoiced and posted to ledger.'][1]),
                    Tables\Actions\Action::make('scanPick')->label('Scan pick')->icon('heroicon-o-qr-code')
                        ->visible(fn (SalesOrder $r) => $r->status === S::Confirmed && auth()->user()->canModule('warehouse_scan', 'manage'))->url(fn (SalesOrder $r) => route('wh.pick', $r)),
                    Tables\Actions\Action::make('pickList')->label('Print pick list')->icon('heroicon-o-printer')
                        ->visible(fn (SalesOrder $r) => $r->status === S::Confirmed || PickList::where('sales_order_id', $r->id)->exists())
                        ->url(fn (SalesOrder $r) => route('wh.print.pick', $r))->openUrlInNewTab(),
                    Tables\Actions\Action::make('packingList')->label('Packing list')->icon('heroicon-o-printer')
                        ->visible(fn (SalesOrder $r) => PickList::where('sales_order_id', $r->id)->whereIn('status', ['picked', 'packed'])->exists())
                        ->url(fn (SalesOrder $r) => route('wh.print.pack', $r))->openUrlInNewTab(),
                    Tables\Actions\Action::make('invoicePdf')->label('Invoice PDF')->icon('heroicon-o-document-arrow-down')
                        ->visible(fn (SalesOrder $r) => $r->status === S::Invoiced)->url(fn (SalesOrder $r) => route('docs.invoice', $r)),
                    Tables\Actions\Action::make('pay')->label('Record payment')->icon('heroicon-o-banknotes')
                        ->visible(fn (SalesOrder $r) => $r->status === S::Invoiced && bccomp($r->balance(), '0', 4) > 0 && auth()->user()->canModule('sales_orders', 'manage'))
                        ->form([
                            Forms\Components\TextInput::make('amount')->numeric()->required()->default(fn (SalesOrder $r) => bcadd($r->balance(), '0', 2)),
                            Forms\Components\Select::make('method')->options(['cash' => 'Cash', 'check' => 'Check', 'card' => 'Card (manual)', 'ach' => 'ACH'])->default('cash')->required(),
                            Forms\Components\TextInput::make('reference'),
                        ])
                        ->action(function (SalesOrder $r, array $data) {
                            app(SalesOrderService::class)->recordPayment($r, $data['amount'], $data['method'], $data['reference'] ?? null);
                            Notification::make()->success()->title('Payment recorded')->send();
                        }),
                    Tables\Actions\Action::make('chargeCard')->label('Charge card / ACH')->icon('heroicon-o-credit-card')
                        ->visible(fn (SalesOrder $r) => $r->status === S::Invoiced && bccomp($r->balance(), '0', 4) > 0 && auth()->user()->canModule('card_payments', 'manage'))
                        ->url(fn (SalesOrder $r) => CardPayments::getUrl(['order' => $r->id])),
                    Tables\Actions\Action::make('creditMemo')->label('Return / credit memo')->icon('heroicon-o-arrow-uturn-left')
                        ->visible(fn (SalesOrder $r) => $r->status === S::Invoiced && auth()->user()->canModule('credit_memos', 'manage'))
                        ->form(fn (SalesOrder $r) => [
                            Forms\Components\DatePicker::make('memo_date')->default(now()->toDateString())->required(),
                            Forms\Components\TextInput::make('reason'),
                            Forms\Components\Repeater::make('lines')->addable(false)->deletable(false)->reorderable(false)
                                ->default($r->lines->map(fn ($l) => ['sales_order_line_id' => $l->id, 'product' => $l->product->sku.' '.$l->product->name,
                                    'max' => bcsub($l->qty, app(CreditMemoService::class)->returnedQty($l), 4), 'qty' => 0, 'disposition' => 'restock'])->all())
                                ->schema([
                                    Forms\Components\Hidden::make('sales_order_line_id'),
                                    Forms\Components\TextInput::make('product')->disabled()->dehydrated(false),
                                    Forms\Components\TextInput::make('max')->label('Returnable')->disabled()->dehydrated(false),
                                    Forms\Components\TextInput::make('qty')->numeric()->minValue(0)->required(),
                                    Forms\Components\Select::make('disposition')->options(['restock' => 'Restock (resellable)', 'damage' => 'Damaged (write off)'])->required(),
                                ])->columns(4),
                            Forms\Components\Toggle::make('post_now')->label('Post now (otherwise saved as draft)')->default(true),
                        ])
                        ->action(function (SalesOrder $r, array $data) {
                            try {
                                $svc = app(CreditMemoService::class);
                                $cm = $svc->create($r, $data['lines'], $data['reason'] ?? null, $data['memo_date']);
                                if ($data['post_now'] ?? false) {
                                    $svc->post($cm);
                                }
                                Notification::make()->success()->title("Credit memo {$cm->number} ".(($data['post_now'] ?? false) ? 'posted' : 'saved as draft'))->send();
                            } catch (Throwable $e) {
                                Notification::make()->danger()->title('Credit memo not created')->body($e->getMessage())->persistent()->send();
                            }
                        }),
                    self::step('cancel', 'Cancel', 'heroicon-o-x-mark', [S::Draft, S::Confirmed, S::Picked], fn ($r) => [app(SalesOrderService::class)->cancel($r), 'Cancelled.'][1], 'danger'),
                ]),
            ]);
    }

    /** Price from the shared PricingEngine for the line's product, qty, the order's customer and date. Qty changes keep a typed (manual) price. */
    private static function fillQuote(Get $get, Set $set, bool $qtyOnly = false): void
    {
        $p = Product::find($get('product_id'));
        if (! $p) {
            return;
        }
        $q = app(PricingEngine::class)->quote($p, Customer::find($get('../../customer_id')), $get('qty') ?: 1, $get('../../order_date'));
        if ($qtyOnly && $get('quoted') !== null && $get('unit_price') !== null && bccomp((string) $get('unit_price'), (string) $get('quoted'), 4) !== 0) {
            $set('quoted', $q->price);

            return;
        }
        $set('unit_price', $q->price);
        $set('quoted', $q->price);
    }

    private static function sourceHint(Get $get, ?SalesOrderLine $record): ?string
    {
        if ($get('quoted') !== null && $get('unit_price') !== null && bccomp((string) $get('unit_price'), (string) $get('quoted'), 4) !== 0) {
            return 'Manual override (quoted $'.number_format((float) $get('quoted'), 2).') - logged on save';
        }
        if ($record?->price_source) {
            return PricingEngine::sourceLabel($record->price_source).($record->price_overridden ? ' (quoted $'.number_format((float) $record->quoted_price, 2).')' : '');
        }

        return $get('quoted') !== null ? 'Engine price' : null;
    }

    /** @param array<int, S> $from */
    private static function step(string $name, string $label, string $icon, array $from, callable $fn, string $color = 'primary'): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)->label($label)->icon($icon)->color($color)->requiresConfirmation()
            ->visible(fn (SalesOrder $r) => in_array($r->status, $from, true) && auth()->user()->canModule('sales_orders', 'manage'))
            ->action(function (SalesOrder $r) use ($fn) {
                try {
                    $msg = $fn($r);
                    Notification::make()->success()->title(is_string($msg) ? $msg : 'Done')->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title('Not done')->body($e->getMessage())->persistent()->send();
                }
            });
    }

    public static function getRelations(): array
    {
        return [HistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSalesOrders::route('/'),
            'create' => Pages\CreateSalesOrder::route('/create'),
            'edit' => Pages\EditSalesOrder::route('/{record}/edit'),
        ];
    }
}
