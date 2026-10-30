<?php

namespace App\Filament\Resources;

use App\Credit\CreditLimit;
use App\Enums\SalesOrderStatus as S;
use App\Filament\Concerns\RoleGated;
use App\Filament\Forms\Components\ProductPickerField;
use App\Filament\Support\OrderActions;
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
use App\Services\Money;
use App\Support\ProductSearch;
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
                \App\Filament\Forms\Components\CustomerPickerField::make('customer_id')->label('Customer')->required()->live()
                    ->rule(fn (Get $get) => function ($attr, $value, $fail) use ($get) { // 0.21: no walk-in / cash customer on an order with regulated lines
                        if (\App\Compliance\WalkInRule::isWalkInId($value)) {
                            $ids = collect((array) $get('lines'))->pluck('product_id')->filter()->all();
                            if ($ids && ($bad = Product::whereIn('id', $ids)->regulated()->first())) {
                                $fail(\App\Compliance\WalkInRule::message($bad));
                            }
                        }
                    })
                    ->afterStateUpdated(function (Set $set, Get $get, $state) { // 0.19.6: ship-to only matters for delivery / ship
                        $set('ship_to_id', $state && $get('fulfillment') !== 'pickup' ? \App\Models\Customer::find($state)?->resolveShipToId() : null);
                    }),
                Forms\Components\Placeholder::make('available_credit')->label('Available credit')
                    ->visible(fn (Get $get) => filled($get('customer_id')))
                    ->content(fn (Get $get) => ($c = Customer::find($get('customer_id'))) ? CreditLimit::summary($c) : ''),
                // 0.19.6: fulfillment lives on the sale. Counter pickup unless the user picks Delivery / Ship.
                Forms\Components\Select::make('fulfillment')->label('Fulfillment')->options(['pickup' => 'Counter pickup', 'delivery' => 'Delivery (route)', 'ship' => 'Ship (carrier)'])
                    ->default('pickup')->required()->selectablePlaceholder(false)->live()
                    ->afterStateUpdated(fn (Set $set, Get $get, $state) => $set('ship_to_id', $state === 'pickup' ? null
                        : ($get('ship_to_id') ?: ($get('customer_id') ? \App\Models\Customer::find($get('customer_id'))?->resolveShipToId() : null))))
                    ->helperText('Counter pickup is the default and needs no ship-to. Delivery / Ship need a ship-to; only Delivery orders go on routes.'),
                Forms\Components\Select::make('ship_to_id')->label('Ship-to')
                    ->visible(fn (Get $get) => in_array($get('fulfillment'), ['delivery', 'ship'], true))
                    ->required(fn (Get $get) => in_array($get('fulfillment'), ['delivery', 'ship'], true))
                    ->options(fn (Get $get) => ShipTo::where('customer_id', $get('customer_id'))->orderByDesc('is_default')->orderBy('id')->get()->mapWithKeys(fn ($s) => [$s->id => "{$s->name} ({$s->state})"]))
                    ->helperText(fn (Get $get) => filled($get('customer_id')) && ! ShipTo::where('customer_id', $get('customer_id'))->exists()
                        ? 'This customer has no ship-to yet: add one on the customer (Ship-to addresses tab), or use Counter pickup.' : null),
                Forms\Components\Placeholder::make('pickup_note')->label('Ship-to')->content('Counter pickup')
                    ->visible(fn (Get $get) => ($get('fulfillment') ?: 'pickup') === 'pickup'),
                Forms\Components\Select::make('sales_rep_id')->label('Sales rep')->relationship('salesRep', 'name')->searchable()->preload()->nullable()->helperText('Blank = customer\'s rep'),
                Forms\Components\Select::make('warehouse_id')->relationship('warehouse', 'name')->required()->default(fn () => Warehouse::where('code', 'MAIN')->value('id') ?? Warehouse::value('id')),
                Forms\Components\DatePicker::make('order_date')->default(now())->required(),
                Forms\Components\DatePicker::make('ship_date')->label('Ship / delivery date')->helperText('Optional. Eligibility rules are checked on the order date and this date.'),
                Forms\Components\Placeholder::make('elig_warnings')->label('Eligibility warnings')
                    ->visible(fn (?SalesOrder $record) => filled($record?->elig_warnings))
                    ->content(fn (?SalesOrder $record) => collect($record?->elig_warnings ?? [])->map(fn ($m, $sku) => $sku.': '.implode(' ', $m))->implode(' | ')),
                Forms\Components\Placeholder::make('status_display')->label('Status')->content(fn (?SalesOrder $record) => ucfirst($record?->status?->value ?? 'draft')),
            ]),
            // 0.20.17: the standard product picker (search / scan / browse, thumbnails, multi-add) feeds the lines.
            Forms\Components\Actions::make([
                ProductPickerField::addLinesAction('lines', function (Product $p, Get $get) {
                    $q = app(PricingEngine::class)->quote($p, Customer::find($get('customer_id')), 1, $get('order_date'));

                    return ['product_id' => $p->id, 'qty' => 1, 'unit_price' => self::displayPrice($q->price), 'quoted' => $q->price];
                }, walkIn: fn (Get $get) => \App\Compliance\WalkInRule::isWalkInId($get('customer_id'))),
            ])->visible(fn (?SalesOrder $record) => ! $record || $record->status === S::Draft)->columnSpanFull()->key('add_products'),
            // 0.20.20: wide lines. Small screens: product on its own full row, qty / price / total under it. Large: one row, product gets half the width.
            Forms\Components\Repeater::make('lines')->relationship()->columns(['default' => 3, 'lg' => 12])
                ->disabled(fn (?SalesOrder $record) => $record && $record->status !== S::Draft)
                ->schema([
                    Forms\Components\Hidden::make('quoted')->dehydrated(false)
                        ->afterStateHydrated(fn (Set $set, ?SalesOrderLine $record) => $set('quoted', $record?->quoted_price)),
                    Forms\Components\Select::make('product_id')->label('Product')->required()->searchable()
                        ->rule(fn (Get $get) => function ($attr, $value, $fail) use ($get) {
                            if (($p = Product::find($value)) && $p->isArchived()) {
                                $fail("Product {$p->sku} is archived and can't be added to new orders.");
                            }
                            // 0.21: walk-in / cash customers cannot buy regulated products
                            if (($p ?? null) && \App\Compliance\WalkInRule::blocks(Customer::find($get('../../customer_id')), $p)) {
                                $fail(\App\Compliance\WalkInRule::message($p));
                            }
                        })
                        ->getSearchResultsUsing(function (string $search, Get $get) {
                            $opts = ProductSearch::options($search);
                            if ($opts && \App\Compliance\WalkInRule::isWalkInId($get('../../customer_id'))) { // 0.21: hide regulated for walk-in / cash
                                $opts = array_intersect_key($opts, array_flip(Product::whereIn('id', array_keys($opts))->notRegulated()->pluck('id')->all()));
                            }

                            return $opts;
                        }) // 0.20.17: same matching as the picker (name/SKU/barcode/vendor item #)
                        ->getOptionLabelUsing(fn ($value) => ($p = Product::find($value)) ? "{$p->sku} {$p->name}" : null)
                        ->live()->afterStateUpdated(function (Get $get, Set $set) {
                            $set('product_unit_id', null); // #25: a new product starts in its stock unit
                            $set('unit_qty', null);
                            self::fillQuote($get, $set);
                        })
                        ->columnSpan(['default' => 3, 'lg' => 6])->extraAttributes(['data-test' => 'line-product']), // 0.20.20 (#8): product keeps half the row; with units, the line total wraps under
                    // #25: order by case / carton; qty below stays the stock-unit quantity used for stock, price, excise and GL
                    ...\App\Filament\Forms\LineUnitFields::make(fn (Get $get, Set $set) => self::fillQuote($get, $set, true)),
                    Forms\Components\TextInput::make('qty')->numeric()->default(1)->required()->minValue(0.0001)->columnSpan(['default' => 1, 'lg' => 2])
                        ->formatStateUsing(fn ($state) => self::displayQty($state)) // 4.0000 -> 4 (stored precision unchanged)
                        ->hidden(fn (Get $get) => filled($get('product_unit_id')))->dehydratedWhenHidden()
                        ->live(onBlur: true)->afterStateUpdated(fn (Get $get, Set $set) => self::fillQuote($get, $set, true)),
                    Forms\Components\TextInput::make('unit_price')->numeric()->required()->prefix('$')->columnSpan(['default' => 1, 'lg' => 2])
                        ->formatStateUsing(fn ($state) => self::displayPrice($state)) // 0.9900 -> 0.99, 0.6550 -> 0.655
                        ->live(onBlur: true)
                        ->helperText(fn (Get $get, ?SalesOrderLine $record) => collect([\App\Filament\Forms\LineUnitFields::perUnitHint($get, 'unit_price'), self::sourceHint($get, $record)])->filter()->implode(' · ') ?: null),
                    Forms\Components\Placeholder::make('line_total_display')->label('Line total')->columnSpan(['default' => 1, 'lg' => 2])
                        ->content(fn (Get $get) => '$'.number_format((float) self::lineTotal($get('qty'), $get('unit_price')), 2)),
                ])->defaultItems(1)->columnSpanFull(),
            // UI audit #5: the amount is visible before Deliver / invoice / collect.
            Forms\Components\Section::make('Totals')->columns(['default' => 2, 'md' => 4])->compact()
                ->schema([
                    Forms\Components\Placeholder::make('totals_subtotal')->label('Subtotal')
                        ->content(fn (Get $get) => '$'.number_format((float) self::formTotal($get('lines')), 2)),
                    Forms\Components\Placeholder::make('totals_total')->label('Order total')
                        ->content(fn (Get $get) => new HtmlString('<strong data-test="order-total">$'.number_format((float) self::formTotal($get('lines')), 2).'</strong>')),
                    Forms\Components\Placeholder::make('totals_paid')->label('Paid / credited')
                        ->visible(fn (?SalesOrder $record) => $record !== null)
                        ->content(fn (?SalesOrder $record) => '$'.number_format((float) Money::add($record?->amount_paid ?? 0, $record?->amount_credited ?? 0), 2)),
                    Forms\Components\Placeholder::make('totals_balance')->label('Balance due')
                        ->visible(fn (?SalesOrder $record) => $record !== null)
                        ->content(fn (Get $get, ?SalesOrder $record) => new HtmlString('<strong data-test="balance-due">$'.number_format((float) Money::s(
                            Money::of(self::formTotal($get('lines')))->minus(Money::of($record?->amount_paid ?? 0))->minus(Money::of($record?->amount_credited ?? 0))), 2).'</strong>')),
                ])->columnSpanFull(),
            Forms\Components\Textarea::make('notes')->columnSpanFull(),
            Forms\Components\Placeholder::make('compliance')->label('Compliance blocks')->columnSpanFull()
                ->visible(fn (?SalesOrder $record) => ! empty($record?->compliance_reasons))
                ->content(fn (?SalesOrder $record) => collect($record?->compliance_reasons ?? [])->map(fn ($r, $sku) => "$sku: ".implode(' ', $r))->implode(' | ')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // #10: empty state with the next step
            ->emptyStateHeading('No sales orders yet')->emptyStateDescription('Create an order, or sell at the counter POS. Portal orders from customers show up here too.')->emptyStateIcon('heroicon-o-shopping-cart')
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('shipTo')) // the customer description reads the ship-to state
            ->columns([
                // UI audit #4: one status column, no 4-line customer wrap, key columns visible on phones.
                // Phones: number over customer in one column so status and total stay on screen.
                Tables\Columns\TextColumn::make('order_summary')->label('Order')->weight('medium')
                    ->getStateUsing(fn (SalesOrder $r) => $r->number)
                    ->description(fn (SalesOrder $r) => new HtmlString(e(\Illuminate\Support\Str::limit((string) $r->customer?->name, 16)).'<br>'.e(self::fulfillmentLabel($r))))
                    ->hiddenFrom('sm'),
                Tables\Columns\TextColumn::make('number')->searchable()->sortable()->visibleFrom('sm'),
                Tables\Columns\TextColumn::make('customer.name')->label('Customer')->searchable()
                    ->limit(32)->tooltip(fn (SalesOrder $r) => mb_strlen((string) $r->customer?->name) > 32 ? $r->customer?->name : null)
                    ->description(fn (SalesOrder $r) => self::fulfillmentLabel($r)) // replaces the separate "St" column
                    ->visibleFrom('sm'),
                Tables\Columns\TextColumn::make('fulfillment')->label('Fulfil.')->badge()->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state) => ['pickup' => 'Pickup', 'delivery' => 'Delivery', 'ship' => 'Ship'][$state] ?? 'Pickup'),
                Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => ucfirst($state->value))
                    ->color(fn ($state) => match ($state) {
                        S::Invoiced => 'success', S::Cancelled => 'gray', S::Draft => 'warning', default => 'info'
                    })
                    ->hiddenFrom('lg'), // phones/tablets: compact badge; large screens use the progress chips below
                Tables\Columns\TextColumn::make('progress')->label('Status')->html()
                    ->getStateUsing(fn (SalesOrder $r) => self::progressHtml($r)) // 0.20 chips: Confirmed > Delivered > Invoiced > Paid / Partial
                    ->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('total')->money('USD')->sortable(),
                Tables\Columns\TextColumn::make('balance_due')->label('Balance')->money('USD')->toggleable()->visibleFrom('md')
                    ->getStateUsing(fn (SalesOrder $r) => in_array($r->status, [S::Delivered, S::Invoiced], true) ? $r->balance() : null),
                Tables\Columns\TextColumn::make('order_date')->date()->sortable()->toggleable()->visibleFrom('sm'),
                Tables\Columns\TextColumn::make('channel')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(S::options()),
                // 0.20.17: target of the dashboard "Open orders" tile (same definition as KpiService::openOrders)
                Tables\Filters\Filter::make('open')->label('Open (confirmed, picked or delivered)')->toggle()
                    ->query(fn ($query) => $query->whereIn('status', [S::Confirmed->value, S::Picked->value, S::Delivered->value])),
                Tables\Filters\SelectFilter::make('payment')->label('Payment')->options(['unpaid' => 'Unpaid', 'partial' => 'Partial', 'paid' => 'Paid'])
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null) ? $query->wherePayment($data['value']) : $query),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    self::step('check', 'Check compliance', 'heroicon-o-shield-check', [S::Draft], function (SalesOrder $r) {
                        $res = app(ComplianceChecker::class)->checkOrder($r);
                        $r->update(['compliance_reasons' => $res ?: null]);

                        return $res ? 'Blocked: '.collect($res)->map(fn ($x, $k) => "$k: ".implode(' ', $x))->implode(' | ') : 'All lines pass.';
                    }),
                    OrderActions::confirm(Tables\Actions\Action::class),
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
                    OrderActions::deliver(Tables\Actions\Action::class),
                    OrderActions::deliverCollect(Tables\Actions\Action::class),
                    Tables\Actions\Action::make('pickupSign')->label('Pickup (signature)')->icon('heroicon-o-pencil-square')
                        ->visible(fn (SalesOrder $r) => in_array($r->status, [S::Confirmed, S::Picked], true) && auth()->user()->canModule('pickup_signature', 'manage'))
                        ->url(fn (SalesOrder $r) => route('driver.pickup', $r)),
                    Tables\Actions\Action::make('deliveryProof')->label('Delivery receipt PDF')->icon('heroicon-o-document-check')
                        ->visible(fn (SalesOrder $r) => $r->signatures()->exists() || $r->deliveryStops()->where('status', '!=', 'pending')->exists())
                        ->url(fn (SalesOrder $r) => route('docs.delivery-proof', $r)),
                    OrderActions::invoice(Tables\Actions\Action::class), // only for orders delivered before 0.20
                    Tables\Actions\Action::make('scanPick')->label('Scan pick')->icon('heroicon-o-qr-code')
                        ->visible(fn (SalesOrder $r) => $r->status === S::Confirmed && auth()->user()->canModule('warehouse_scan', 'manage'))->url(fn (SalesOrder $r) => route('wh.pick', $r)),
                    Tables\Actions\Action::make('pickList')->label('Print pick list')->icon('heroicon-o-printer')
                        ->visible(fn (SalesOrder $r) => $r->status === S::Confirmed || PickList::where('sales_order_id', $r->id)->exists())
                        ->url(fn (SalesOrder $r) => route('wh.print.pick', $r))->openUrlInNewTab(),
                    Tables\Actions\Action::make('packingList')->label('Packing list')->icon('heroicon-o-printer')
                        ->visible(fn (SalesOrder $r) => PickList::where('sales_order_id', $r->id)->whereIn('status', ['picked', 'packed'])->exists())
                        ->url(fn (SalesOrder $r) => route('wh.print.pack', $r))->openUrlInNewTab(),
                    Tables\Actions\Action::make('invoiceView')->label('View invoice')->icon('heroicon-o-eye')
                        ->visible(fn (SalesOrder $r) => $r->status === S::Invoiced)->url(fn (SalesOrder $r) => route('docs.invoice.view', $r))->openUrlInNewTab(),
                    Tables\Actions\Action::make('invoicePdf')->label('Invoice PDF')->icon('heroicon-o-document-arrow-down')
                        ->visible(fn (SalesOrder $r) => $r->status === S::Invoiced)->url(fn (SalesOrder $r) => route('docs.invoice', $r)),
                    OrderActions::pay(Tables\Actions\Action::class),
                    OrderActions::chargeCard(Tables\Actions\Action::class),
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
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('bulkInvoice')->label('Invoice selected')->icon('heroicon-o-document-text')->requiresConfirmation()
                    ->visible(fn () => OrderActions::canManage())
                    ->action(fn (\Illuminate\Support\Collection $records) => self::bulkInvoice($records)),
            ]);
    }

    /** 0.20: invoice every selected Delivered order (others are skipped). */
    public static function bulkInvoice(iterable $records): void
    {
        $ok = 0;
        $err = [];
        foreach ($records as $r) {
            if ($r->status !== S::Delivered) {
                continue;
            }
            try {
                app(SalesOrderService::class)->invoice($r);
                $ok++;
            } catch (Throwable $e) {
                $err[] = "{$r->number}: {$e->getMessage()}";
            }
        }
        $n = Notification::make()->title("Invoiced {$ok} order(s).")->body($err ? implode(' | ', $err) : null);
        ($err ? $n->warning()->persistent() : $n->success())->send();
    }

    /** Price from the shared PricingEngine for the line's product, qty, the order's customer and date. Qty changes keep a typed (manual) price. */
    /** Exact qty x price for display; anything not yet a number (e.g. "4." while typing) counts as 0. */
    public static function lineTotal(mixed $qty, mixed $price): string
    {
        try {
            return is_numeric($qty) && is_numeric($price) ? Money::mul(trim((string) $qty), trim((string) $price)) : '0.0000';
        } catch (\Brick\Math\Exception\MathException) {
            return '0.0000'; // display helper: never break the form over a half-typed value
        }
    }

    /** Sum of the repeater's current lines, so unsaved edits show in the totals. */
    public static function formTotal(?array $lines): string
    {
        return Money::add(...array_map(fn ($l) => self::lineTotal($l['qty'] ?? null, $l['unit_price'] ?? null), array_values($lines ?? [])) ?: [0]);
    }

    /** 4.0000 -> 4, 2.5000 -> 2.5 (display only; the column keeps 4 decimals). */
    public static function displayQty(mixed $v): mixed
    {
        if (! is_numeric($v) || ! str_contains((string) $v, '.')) {
            return $v;
        }

        return rtrim(rtrim((string) $v, '0'), '.');
    }

    /** At least 2 decimals, no trailing zeros beyond that: 0.9900 -> 0.99, 0.6550 -> 0.655, 24.0000 -> 24.00. */
    public static function displayPrice(mixed $v): mixed
    {
        if (! is_numeric($v) || ! str_contains((string) $v, '.')) {
            return $v;
        }
        [$int, $dec] = explode('.', (string) $v, 2);
        $dec = rtrim($dec, '0');

        return $int.'.'.str_pad($dec, 2, '0');
    }

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
        $set('unit_price', self::displayPrice($q->price)); // same value, shown without trailing zeros
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

    /** List description under the customer: "Pickup", or "Delivery · KY" (ship-to state, needed for PACT checks at a glance). */
    public static function fulfillmentLabel(SalesOrder $r): string
    {
        $f = ['pickup' => 'Pickup', 'delivery' => 'Delivery', 'ship' => 'Ship'][$r->fulfillment] ?? 'Pickup';
        $state = $f === 'Pickup' ? null : $r->shipTo?->state;

        return $state ? "{$f} · {$state}" : $f;
    }

    /** Single status column on large screens: progress chips, with Draft / Cancelled as badges and "Picked" named. */
    public static function progressHtml(SalesOrder $r): string
    {
        return match ($r->status) {
            S::Draft => '<span style="display:inline-block;padding:1px 8px;border-radius:9999px;font-size:11px;font-weight:600;background:#fef3c7;color:#92400e">Draft</span>',
            S::Cancelled => '<span style="display:inline-block;padding:1px 8px;border-radius:9999px;font-size:11px;font-weight:600;background:#f3f4f6;color:#4b5563">Cancelled</span>',
            S::Picked => str_replace('>Confirmed<', '>Picked<', $r->stageChips()),
            default => $r->stageChips(),
        };
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
