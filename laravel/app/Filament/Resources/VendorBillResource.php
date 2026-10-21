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

    protected static ?string $navigationGroup = 'Accounting';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Vendor bills';

    protected static ?int $navigationSort = 10;

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
        return $form->schema([
            Forms\Components\Section::make()->columns(['default' => 1, 'md' => 3])->schema([
                Forms\Components\TextInput::make('number')->disabled()->dehydrated(false)->placeholder('Assigned on save'),
                Forms\Components\Select::make('vendor_id')->relationship('vendor', 'name')->searchable()->preload()->required()
                    ->disabled(fn (?VendorBill $record) => (bool) $record?->purchase_order_id),
                Forms\Components\TextInput::make('vendor_invoice')->label('Vendor invoice #')->maxLength(120),
                Forms\Components\DatePicker::make('bill_date')->default(now())->required(),
                Forms\Components\DatePicker::make('due_date')->default(now()->addDays(30))->required(),
                Forms\Components\Placeholder::make('po')->label('Purchase order')->content(fn (?VendorBill $record) => $record?->purchaseOrder?->number ?? 'Standalone'),
                Forms\Components\Textarea::make('notes')->columnSpanFull(),
            ]),
            Forms\Components\Repeater::make('lines')->relationship()->columns(['default' => 2, 'md' => 5])
                ->helperText('PO lines are matched to what was received at the PO cost. Add extra lines (freight, fees) with a GL account. Standalone bills need a GL account on every line.')
                ->schema([
                    Forms\Components\Select::make('account_code')->label('GL account')->options(fn () => static::accountOptions())->searchable()
                        ->disabled(fn (Forms\Get $get) => (bool) $get('purchase_order_line_id'))
                        ->required(fn (Forms\Get $get) => ! $get('purchase_order_line_id')),
                    Forms\Components\TextInput::make('description')->columnSpan(['md' => 2]),
                    Forms\Components\TextInput::make('qty')->numeric()->default(1)->required(),
                    Forms\Components\TextInput::make('unit_cost')->numeric()->required(),
                    Forms\Components\Hidden::make('purchase_order_line_id'),
                    Forms\Components\Hidden::make('product_id'),
                    Forms\Components\Hidden::make('amount')->default(0),
                ])
                ->mutateRelationshipDataBeforeCreateUsing(fn (array $d) => $d + ['amount' => bcmul((string) ($d['qty'] ?? 0), (string) ($d['unit_cost'] ?? 0), 4)])
                ->mutateRelationshipDataBeforeSaveUsing(fn (array $d) => array_merge($d, ['amount' => bcmul((string) ($d['qty'] ?? 0), (string) ($d['unit_cost'] ?? 0), 4)])),
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
