<?php

namespace App\Filament\Resources;

use App\Accounting\ApService;
use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\PurchaseOrderResource\Pages;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Services\PurchaseOrderService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Throwable;

class PurchaseOrderResource extends Resource
{
    use RoleGated;

    protected static string $module = 'purchase_orders';

    protected static ?string $model = PurchaseOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationGroup = 'Purchasing';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'number';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(['default' => 1, 'md' => 3])->schema([
                Forms\Components\TextInput::make('number')->disabled()->dehydrated(false)->placeholder('Assigned on save'),
                Forms\Components\Select::make('vendor_id')->relationship('vendor', 'name')->searchable()->preload()->required(),
                Forms\Components\Select::make('warehouse_id')->relationship('warehouse', 'name')->required()->default(1),
                Forms\Components\DatePicker::make('order_date')->default(now())->required(),
                Forms\Components\Select::make('status')->options(['draft' => 'Draft', 'ordered' => 'Ordered', 'cancelled' => 'Cancelled'])->default('draft')
                    ->disabled(fn (?PurchaseOrder $record) => $record?->status === 'received'),
                Forms\Components\TextInput::make('over_receipt_pct')->label('Over-receipt allowance %')->numeric()->minValue(0)->maxValue(100)
                    ->placeholder('Global ('.(float) Setting::get('over_receipt_pct', 0).'%)')
                    ->helperText('Blank = global setting. Over-receipts are logged.')
                    ->visible(fn () => auth()->user()?->isAdmin()),
            ]),
            Forms\Components\Section::make('Inbound shipping')->description('The receiving team is alerted when the PO is sent (Ordered) and when the ETA or tracking changes.')
                ->columns(['default' => 1, 'md' => 3])->schema([
                    Forms\Components\DatePicker::make('expected_at')->label('Expected arrival'),
                    Forms\Components\Select::make('carrier_code')->label('Carrier')->options(\App\Purchasing\InboundTracking::CARRIERS)->live(),
                    Forms\Components\TextInput::make('carrier_name')->label('Carrier name')->maxLength(80)
                        ->visible(fn (Forms\Get $get) => $get('carrier_code') === 'other')->placeholder('e.g. Estes, R+L, own truck'),
                    Forms\Components\TagsInput::make('tracking_numbers')->label('Tracking number(s)')->placeholder('Add a number, press Enter')->splitKeys([',', ' ', 'Enter'])->columnSpan(['md' => 2]),
                    Forms\Components\TextInput::make('tracking_url')->label('Custom tracking URL')->url()->maxLength(500)
                        ->helperText('Optional. Overrides the carrier link; use {tracking} where the number goes.'),
                    Forms\Components\Placeholder::make('track_links')->label('Track')->columnSpanFull()
                        ->visible(fn (?PurchaseOrder $record) => $record && \App\Purchasing\InboundTracking::links($record))
                        ->content(fn (?PurchaseOrder $record) => new \Illuminate\Support\HtmlString($record ? \App\Purchasing\InboundTracking::html($record) : '')),
                ]),
            Forms\Components\Repeater::make('lines')->relationship()->columns(['default' => 2, 'md' => 4])
                ->disabled(fn (?PurchaseOrder $record) => in_array($record?->status, ['received', 'partial'], true))
                ->schema([
                    Forms\Components\Select::make('product_id')->label('Product')->required()->searchable()
                        ->rule(fn () => function ($attr, $value, $fail) {
                            if (($p = Product::find($value)) && $p->isArchived()) {
                                $fail("Product {$p->sku} is archived and can't be added to new orders.");
                            }
                        })
                        ->getSearchResultsUsing(fn (string $search) => Product::notArchived()->where(fn ($w) => $w->where('sku', 'like', "%$search%")->orWhere('upc', 'like', "%$search%")->orWhere('name', 'like', "%$search%"))->limit(30)->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} {$p->name}"]))
                        ->getOptionLabelUsing(fn ($value) => ($p = Product::find($value)) ? "{$p->sku} {$p->name}" : null)
                        ->live()->afterStateUpdated(fn ($state, Set $set) => $set('unit_cost', Product::find($state)?->cost))
                        ->columnSpan(['default' => 2, 'md' => 2]),
                    Forms\Components\TextInput::make('qty')->numeric()->default(1)->required()->minValue(0.0001),
                    Forms\Components\TextInput::make('unit_cost')->numeric()->required()->prefix('$'),
                ])->defaultItems(1)->columnSpanFull(),
            Forms\Components\Textarea::make('notes')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('number')->searchable(),
                Tables\Columns\TextColumn::make('vendor.name')->searchable()->wrap()->extraAttributes(['style' => 'min-width:12rem']), // UI audit #7: wraps to 2 lines, not 4
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('total')->money('USD'),
                Tables\Columns\TextColumn::make('order_date')->date()->toggleable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('warehouse.code')->label('WH')->toggleable()->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('expected_at')->label('Expected')->date()->sortable()->toggleable()->visibleFrom('sm'),
                Tables\Columns\TextColumn::make('track')->label('Tracking')->html()->toggleable()->visibleFrom('lg')
                    ->getStateUsing(fn (PurchaseOrder $r) => trim((\App\Purchasing\InboundTracking::carrierLabel($r) ?? '').' '.\App\Purchasing\InboundTracking::html($r))),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // UI audit #7: secondary row actions in one menu so the vendor column isn't squeezed.
                Tables\Actions\ActionGroup::make([
                    \App\Filament\Support\UpdateTrackingAction::table(),
                    Tables\Actions\Action::make('scanReceive')->label('Scan receive')->icon('heroicon-o-qr-code')
                        ->visible(fn (PurchaseOrder $r) => in_array($r->status, ['draft', 'ordered', 'partial'], true) && auth()->user()->canModule('warehouse_scan', 'manage'))
                        ->url(fn (PurchaseOrder $r) => route('wh.receive', $r)),
                    Tables\Actions\Action::make('bill')->label('Create bill')->icon('heroicon-o-document-text')
                        ->visible(fn (PurchaseOrder $r) => auth()->user()->canModule('ap', 'manage')
                            && $r->lines()->whereColumn('qty_billed', '<', 'qty_received')->exists())
                        ->modalDescription('Creates a DRAFT vendor bill for everything received and not yet billed, at PO cost. Adjust quantities/costs to the vendor invoice; differences are flagged by the 3-way match.')
                        ->form([
                            Forms\Components\TextInput::make('vendor_invoice')->label('Vendor invoice #'),
                            Forms\Components\DatePicker::make('bill_date')->default(now())->required(),
                            Forms\Components\TextInput::make('terms_days')->label('Due in (days)')->integer()->default(30)->required(),
                        ])
                        ->action(function (PurchaseOrder $r, array $data) {
                            try {
                                $b = app(ApService::class)->draftFromPurchaseOrder($r, $data['vendor_invoice'] ?? null, $data['bill_date'], (int) $data['terms_days']);

                                return redirect(VendorBillResource::getUrl('edit', ['record' => $b]));
                            } catch (Throwable $e) {
                                Notification::make()->danger()->title('No bill created')->body($e->getMessage())->persistent()->send();
                            }
                        }),
                    Tables\Actions\Action::make('receive')->label('Receive')->icon('heroicon-o-inbox-arrow-down')
                        ->modalDescription('Enter quantities received now (partial receipts allowed). Posts Dr Inventory / Cr 2050 Received Not Billed (cleared by the vendor bill), and KY excise on untaxed snuff/chew from non-KY-licensed vendors.')
                        ->visible(fn (PurchaseOrder $r) => in_array($r->status, ['draft', 'ordered', 'partial'], true) && auth()->user()->canModule('purchase_orders', 'receive'))
                        ->form(fn (PurchaseOrder $r) => $r->lines()->with('product')->get()
                            ->filter(fn ($l) => bccomp($l->qty, $l->qty_received, 4) > 0)
                            ->map(fn ($l) => Forms\Components\TextInput::make("q{$l->id}")
                                ->label("{$l->product->sku} {$l->product->name} (open ".rtrim(rtrim(bcsub($l->qty, $l->qty_received, 4), '0'), '.').')')
                                ->numeric()->minValue(0)->default(bcsub($l->qty, $l->qty_received, 4)))
                            ->values()->all())
                        ->action(function (PurchaseOrder $r, array $data) {
                            $q = [];
                            foreach ($data as $k => $v) {
                                $q[(int) substr($k, 1)] = $v === null || $v === '' ? 0 : $v;
                            }
                            try {
                                app(PurchaseOrderService::class)->receive($r, $q);
                                Notification::make()->success()->title("PO {$r->number}: ".$r->fresh()->status)->send();
                            } catch (Throwable $e) {
                                Notification::make()->danger()->title('Not received')->body($e->getMessage())->persistent()->send();
                            }
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [\App\Filament\RelationManagers\HistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseOrders::route('/'),
            'create' => Pages\CreatePurchaseOrder::route('/create'),
            'view' => Pages\ViewPurchaseOrder::route('/{record}'),
            'edit' => Pages\EditPurchaseOrder::route('/{record}/edit'),
        ];
    }
}
