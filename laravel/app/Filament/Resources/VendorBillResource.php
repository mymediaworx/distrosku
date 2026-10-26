<?php

namespace App\Filament\Resources;

use App\Accounting\ApService;
use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\VendorBillResource\Pages;
use App\Models\Account;
use App\Models\VendorBill;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** AP vendor bills: from a PO receipt (3-way match) or standalone (expenses). Posted bills are never edited, only voided. */
class VendorBillResource extends Resource
{
    use RoleGated;

    protected static string $module = 'ap';

    protected static ?string $model = VendorBill::class;

    protected static ?string $navigationGroup = 'Bills (AP)';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Vendor bills';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'number';

    public static function canEdit(Model $record): bool
    {
        return $record->status === 'draft' && static::allowed('manage');
    }

    public static function canDelete(Model $record): bool
    {
        return $record->status === 'draft' && static::allowed('manage');
    }

    public static function accountOptions(): array
    {
        return Account::where('active', true)->whereNotIn('code', ['2000', '2050', '1100'])->orderBy('code')->get()
            ->mapWithKeys(fn ($a) => [$a->code => "{$a->code} {$a->name}"])->all();
    }

    public static function form(Form $form): Form
    {
        $lineTotal = fn ($qty, $cost) => bcmul(is_numeric($qty) ? (string) $qty : '0', is_numeric($cost) ? (string) $cost : '0', 4);

        // 0.20.5: wider layout: tidy header grid, full-width compact table-style lines, totals right-aligned.
        return $form->columns(1)->schema([
            Forms\Components\Section::make('Bill')->columns(['default' => 1, 'sm' => 2, 'lg' => 4])->schema([
                Forms\Components\Select::make('vendor_id')->relationship('vendor', 'name')->searchable()->required()
                    ->disabled(fn (?VendorBill $record) => (bool) $record?->purchase_order_id)->columnSpan(['sm' => 2]),
                Forms\Components\TextInput::make('vendor_invoice')->label('Vendor invoice #')->maxLength(120),
                Forms\Components\TextInput::make('number')->label('Bill #')->disabled()->dehydrated(false)->placeholder('Assigned on save'),
                Forms\Components\DatePicker::make('bill_date')->default(now())->required(),
                Forms\Components\DatePicker::make('due_date')->default(now()->addDays(30))->required(),
                Forms\Components\Placeholder::make('po')->label('Purchase order')->content(fn (?VendorBill $record) => $record?->purchaseOrder?->number ?? 'Standalone'),
                Forms\Components\Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]),
            Forms\Components\Section::make('Lines')->columnSpanFull()->extraAttributes(['class' => 'vb-lines'])
                ->description('PO lines are matched to what was received at the PO cost. Add extra lines (freight, fees) with a GL account. Standalone bills need a GL account on every line.')
                ->schema([
                    Forms\Components\Repeater::make('lines')->relationship()->hiddenLabel()->columnSpanFull()
                        ->columns(['default' => 2, 'md' => 12])->reorderable()->orderColumn(false)->addActionLabel('Add line')
                        ->itemLabel(fn (array $state) => $state['description'] ?? null)->collapsible()->live()
                        ->extraAttributes(['class' => 'vb-lines-repeater'])
                        ->schema([
                            Forms\Components\Select::make('product_id')->label('Product / item')->relationship('product', 'name')
                                ->getOptionLabelFromRecordUsing(fn ($p) => "{$p->sku} {$p->name}")->searchable(['sku', 'name', 'upc'])
                                ->disabled(fn (Forms\Get $get) => (bool) $get('purchase_order_line_id'))->dehydrated()
                                ->live()->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                    if ($state && ($p = \App\Models\Product::find($state))) {
                                        $get('description') ?: $set('description', "{$p->sku} {$p->name}");
                                        $get('unit_cost') ?: $set('unit_cost', $p->cost);
                                    }
                                })->columnSpan(['default' => 2, 'md' => 3]),
                            Forms\Components\TextInput::make('description')->columnSpan(['default' => 2, 'md' => 3]),
                            Forms\Components\Select::make('account_code')->label('GL account')->options(fn () => static::accountOptions())->searchable()
                                ->disabled(fn (Forms\Get $get) => (bool) $get('purchase_order_line_id'))
                                ->required(fn (Forms\Get $get) => ! $get('purchase_order_line_id'))->columnSpan(['default' => 2, 'md' => 2]),
                            Forms\Components\TextInput::make('qty')->numeric()->default(1)->required()->live(onBlur: true)->columnSpan(['default' => 1, 'md' => 1]),
                            Forms\Components\TextInput::make('unit_cost')->label('Unit cost')->numeric()->required()->live(onBlur: true)->columnSpan(['default' => 1, 'md' => 2]),
                            Forms\Components\Placeholder::make('line_total')->label('Line total')
                                ->content(fn (Forms\Get $get) => '$'.number_format((float) $lineTotal($get('qty'), $get('unit_cost')), 2))
                                ->extraAttributes(['class' => 'text-right font-medium tabular-nums'])->columnSpan(['default' => 2, 'md' => 1]),
                            Forms\Components\Hidden::make('purchase_order_line_id'),
                            Forms\Components\Hidden::make('amount')->default(0),
                        ])
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $d) => $d + ['amount' => bcmul((string) ($d['qty'] ?? 0), (string) ($d['unit_cost'] ?? 0), 4)])
                        ->mutateRelationshipDataBeforeSaveUsing(fn (array $d) => array_merge($d, ['amount' => bcmul((string) ($d['qty'] ?? 0), (string) ($d['unit_cost'] ?? 0), 4)])),
                    Forms\Components\Placeholder::make('bill_total')->label('Bill total')->hiddenLabel()
                        ->content(function (Forms\Get $get) use ($lineTotal) {
                            $t = '0';
                            foreach ((array) $get('lines') as $l) {
                                $t = bcadd($t, $lineTotal($l['qty'] ?? 0, $l['unit_cost'] ?? 0), 4);
                            }

                            return new \Illuminate\Support\HtmlString('<div class="text-right text-base"><span class="text-gray-500">Bill total</span> <strong class="tabular-nums">$'.number_format((float) $t, 2).'</strong></div>');
                        }),
                ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\TextEntry::make('number'),
            Infolists\Components\TextEntry::make('vendor.name'),
            Infolists\Components\TextEntry::make('vendor_invoice')->label('Vendor invoice #'),
            Infolists\Components\TextEntry::make('purchaseOrder.number')->label('PO')->placeholder('Standalone'),
            Infolists\Components\TextEntry::make('bill_date')->date(),
            Infolists\Components\TextEntry::make('due_date')->date(),
            Infolists\Components\TextEntry::make('status')->badge(),
            Infolists\Components\TextEntry::make('match_status')->label('3-way match')->badge()
                ->color(fn ($state) => match ($state) {
                    'matched' => 'success', 'variance' => 'danger', default => 'gray'
                }),
            Infolists\Components\TextEntry::make('total')->money('USD'),
            Infolists\Components\TextEntry::make('amount_paid')->money('USD'),
            Infolists\Components\TextEntry::make('match_notes')->label('Match notes')->listWithLineBreaks()->columnSpanFull()->placeholder('None'),
            Infolists\Components\RepeatableEntry::make('lines')->schema([
                Infolists\Components\TextEntry::make('description'),
                Infolists\Components\TextEntry::make('account_code')->label('GL')->placeholder('PO'),
                Infolists\Components\TextEntry::make('qty'),
                Infolists\Components\TextEntry::make('unit_cost')->money('USD'),
                Infolists\Components\TextEntry::make('amount')->money('USD'),
            ])->columns(5)->columnSpanFull(),
        ])->columns(4);
    }

    public static function postAction(string $class): mixed
    {
        return $class::make('post')->label('Post')->icon('heroicon-o-check-circle')->color('success')
            ->visible(fn (VendorBill $r) => $r->status === 'draft' && static::allowed('manage'))
            ->modalDescription(fn (VendorBill $r) => $r->match_status === 'variance'
                ? 'This bill does not match the PO / receipt: '.implode(' ', $r->match_notes ?? []).' Posting needs an admin to approve the variance.'
                : 'Posts Dr Received Not Billed / expense accounts, Cr Accounts Payable. Posted bills can only be voided.')
            ->form(fn (VendorBill $r) => $r->match_status === 'variance' ? [Forms\Components\Checkbox::make('approve')->label('I approve this variance')->required()->accepted()] : [])
            ->action(function (VendorBill $r, array $data) {
                try {
                    app(ApService::class)->post($r, auth()->user(), (bool) ($data['approve'] ?? false));
                    Notification::make()->success()->title("Bill {$r->number} posted")->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title('Not posted')->body($e->getMessage())->persistent()->send();
                }
            });
    }

    public static function voidAction(string $class): mixed
    {
        return $class::make('void')->label('Void')->icon('heroicon-o-x-circle')->color('danger')
            ->visible(fn (VendorBill $r) => in_array($r->status, ['draft', 'posted'], true) && static::allowed('manage'))
            ->form([Forms\Components\TextInput::make('reason')->required()])
            ->action(function (VendorBill $r, array $data) {
                try {
                    app(ApService::class)->void($r, $data['reason']);
                    Notification::make()->success()->title("Bill {$r->number} voided")->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title('Not voided')->body($e->getMessage())->persistent()->send();
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('number')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('vendor.name')->searchable(),
                Tables\Columns\TextColumn::make('vendor_invoice')->label('Vendor inv #')->searchable(),
                Tables\Columns\TextColumn::make('purchaseOrder.number')->label('PO')->placeholder('—'),
                Tables\Columns\TextColumn::make('bill_date')->date()->sortable(),
                Tables\Columns\TextColumn::make('due_date')->date()->sortable(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('match_status')->label('Match')->badge()
                    ->color(fn ($state) => match ($state) {
                        'matched' => 'success', 'variance' => 'danger', default => 'gray'
                    }),
                Tables\Columns\TextColumn::make('total')->money('USD'),
                Tables\Columns\TextColumn::make('open')->label('Open')->state(fn (VendorBill $r) => $r->balance())->money('USD'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['draft' => 'Draft', 'posted' => 'Posted', 'partial' => 'Partly paid', 'paid' => 'Paid', 'void' => 'Void']),
                Tables\Filters\SelectFilter::make('vendor')->relationship('vendor', 'name'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                static::postAction(Tables\Actions\Action::class),
                static::voidAction(Tables\Actions\Action::class),
            ]);
    }

    public static function getRelations(): array
    {
        return [\App\Filament\RelationManagers\HistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVendorBills::route('/'),
            'create' => Pages\CreateVendorBill::route('/create'),
            'view' => Pages\ViewVendorBill::route('/{record}'),
            'edit' => Pages\EditVendorBill::route('/{record}/edit'),
        ];
    }
}
