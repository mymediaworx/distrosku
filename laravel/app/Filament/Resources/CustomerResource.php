<?php

namespace App\Filament\Resources;

use App\Credit\CreditLimit;
use App\Delivery\DeliveryDays;
use App\Filament\Concerns\RoleGated;
use App\Filament\RelationManagers\HistoryRelationManager;
use App\Filament\Resources\CustomerResource\Pages;
use App\Models\Customer;
use App\Services\CustomerBalanceService;
use App\Services\Money;
use App\Support\CustomerType;
use App\Support\Terms;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CustomerResource extends Resource
{
    /** 0.17.3: only owner / admin / brandworx_admin may delete customers. */
    public static function canDelete(Model $record): bool
    {
        return static::canDeleteAny();
    }

    public static function canDeleteAny(): bool
    {
        return (bool) auth()->user()?->isAdmin() && auth()->user()->canModule('customers', 'manage');
    }

    use RoleGated;

    protected static string $module = 'customers';

    protected static ?string $model = Customer::class;

    protected static ?string $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // 0.20.13: Customer → relation tabs → Terms & pricing → …
                Forms\Components\Section::make('Customer')->schema([
                    Forms\Components\TextInput::make('code')->label('Account Number')->required()->maxLength(30)->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('name')->label('Customer Name')->required(),
                    Forms\Components\TextInput::make('dba')->label('DBA')->maxLength(190)->nullable()
                        ->helperText('Doing business as / trading name. Shown under the customer name on invoices and receipts when set.'),
                    Forms\Components\Select::make('type')->label('Customer type')
                        ->options(CustomerType::OPTIONS)->default('retailer')->required()
                        ->helperText(fn ($record) => $record?->type_legacy ? 'Before 0.20.3 this was: '.$record->type_legacy : null),
                    Forms\Components\TextInput::make('email')->email(),
                    Forms\Components\TextInput::make('phone')->tel(),
                    Forms\Components\Toggle::make('active')->required()->default(true)->inline(false),
                ])->columns(['default' => 1, 'sm' => 2, 'xl' => 3]),
                // Mid-form relation tabs (edit only; hidden on create until a record exists).
                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\View::make('filament.customers.embedded-relations'),
                    ])
                    ->visible(fn (?Customer $record) => filled($record?->getKey()))
                    ->columnSpanFull(),
                Forms\Components\Section::make('Terms & pricing')->schema([
                    Forms\Components\Select::make('terms')->label('Terms')
                        ->options(fn (?Customer $record) => Terms::options($record?->terms))
                        ->default('Net 15')->required()->native(false)->searchable(),
                    Forms\Components\TextInput::make('state_tax_id')->label('State tax ID')->maxLength(40)
                        ->helperText('Sent to Marketing (State Tax ID custom field).'),
                    Forms\Components\TextInput::make('fein')->label('Federal EIN')->maxLength(20)->placeholder('12-3456789')
                        ->rule('nullable')->regex('/^\s*\d{2}-?\d{7}\s*$/')
                        ->validationMessages(['regex' => 'Federal EIN should be 9 digits, like 12-3456789.'])
                        ->dehydrateStateUsing(fn ($state) => static::formatEin($state)),
                    Forms\Components\Select::make('customer_group_id')->label('Price group')->relationship('customerGroup', 'name')->searchable()->preload()->nullable()
                        ->helperText('Group prices and group promotions apply to this customer. Add groups in Pricing › Price groups.'),
                    Forms\Components\Select::make('sales_rep_id')->label('Sales rep')->relationship('salesRep', 'name')->searchable()->preload()->nullable(),
                ])->columns(['default' => 1, 'sm' => 2, 'xl' => 3]),
                Forms\Components\Section::make('Credit')->schema([
                    Forms\Components\TextInput::make('credit_limit')->required()->numeric()->default(0)
                        ->step(0.01)->inputMode('decimal')
                        ->rule('decimal:0,2')
                        ->disabled(fn () => ! auth()->user()?->isAdmin())
                        ->dehydrated(fn () => (bool) auth()->user()?->isAdmin())
                        ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? 0 : round((float) $state, 2))
                        ->helperText(fn () => (auth()->user()?->isAdmin() ? '' : 'Only an owner/admin can change the credit limit. ').'0 = no limit. Charges to the account that would go over the limit are blocked (owner/admin can override per order).'),
                    Forms\Components\Placeholder::make('available_credit')->label('Available credit')
                        ->visible(fn (?Customer $record) => $record !== null)
                        ->content(fn (?Customer $record) => $record ? CreditLimit::summary($record) : ''),
                    // Admin-level only. Not dehydrated: saved via CustomerBalanceService (ledger + audit).
                    Forms\Components\Fieldset::make('Beginning balance (migrated from previous system)')
                        ->visible(fn () => CustomerBalanceService::canManage(auth()->user()))
                        ->schema([
                            Forms\Components\TextInput::make('beginning_balance')->numeric()->default('0.00')
                                ->step(0.01)->inputMode('decimal')
                                ->rule('decimal:0,2')
                                ->helperText('Format XXXXXXXXX.XX (2 decimal places). Negative = customer credit. Changes post Dr/Cr 1100 AR vs 3900 Opening Balance Equity for the difference.')
                                ->dehydrated(false),
                            Forms\Components\DatePicker::make('beginning_balance_date')->dehydrated(false),
                            Forms\Components\TextInput::make('beginning_balance_note')->label('Reason for change')->dehydrated(false),
                        ])->columns(['default' => 1, 'md' => 3])->columnSpanFull(),
                ])->columns(['default' => 1, 'md' => 2]),
                Forms\Components\Section::make('Delivery & marketing')->schema([
                    Forms\Components\CheckboxList::make('delivery_days')->options(DeliveryDays::DAYS)->columns(['default' => 2, 'sm' => 4, 'lg' => 7])->gridDirection('row')
                        ->formatStateUsing(fn ($state) => DeliveryDays::parse($state))->dehydrateStateUsing(fn ($state) => DeliveryDays::toCsv($state))->columnSpanFull(),
                    Forms\Components\Toggle::make('email_opt_in')->label('Email opt-in (marketing / notices)')
                        ->helperText('Drives email consent in Marketing. Off sets Email to DND there.'),
                    Forms\Components\Toggle::make('dunning_email_optout')->label('Do not auto-email dunning notices')
                        ->helperText('On = this customer\'s overdue notices are never emailed; they go to the Dunning print list instead.'),
                    Forms\Components\Toggle::make('sms_opt_in')->label('SMS opt-in')
                        ->helperText('Only with recorded consent (A2P 10DLC). Drives SMS consent in Marketing. Off sets SMS to DND there.'),
                ])->columns(['default' => 1, 'md' => 2])->collapsible(),
                Forms\Components\Section::make('Balance & aging')
                    ->visible(fn (?Customer $record) => (bool) $record?->exists)
                    ->schema([
                        Forms\Components\Placeholder::make('aging')->hiddenLabel()
                            ->content(fn (?Customer $record) => $record ? view('docs.aging-inline', ['aging' => app(CustomerBalanceService::class)->aging($record)]) : null),
                    ]),
                Forms\Components\Section::make('MSA')->visible(fn () => (bool) auth()->user()?->isAdmin())->schema([
                    Forms\Components\Select::make('msa_class_of_trade')->label('Class of trade (blank = from type)')
                        ->options(['Retailer' => 'Retailer', 'Distributor' => 'Distributor', 'Other' => 'Other']),
                    Forms\Components\Toggle::make('msa_promo_acceptance')->label('Accepts promotional tobacco product')->inline(false),
                ])->columns(['default' => 1, 'md' => 2])->collapsible(),
            ]);
    }

    /** 0.20.6: store Federal EIN as XX-XXXXXXX when 9 digits were entered; otherwise keep what was typed. */
    public static function formatEin(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $d = preg_replace('/\D/', '', $v);

        return strlen($d) === 9 ? substr($d, 0, 2).'-'.substr($d, 2) : $v;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Account Number')
                    ->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Customer Name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('dba')->label('DBA')
                    ->searchable()->toggleable(isToggledHiddenByDefault: true)->placeholder('—'),
                Tables\Columns\TextColumn::make('type')->label('Type')
                    ->formatStateUsing(fn ($state) => CustomerType::label($state))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()->visibleFrom('lg')->toggleable(),
                Tables\Columns\TextColumn::make('phone')
                    ->searchable()->visibleFrom('md')->toggleable(),
                Tables\Columns\TextColumn::make('terms')
                    ->searchable()->badge()->color('gray')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('customerGroup.name')->label('Price group')->badge()->color('info')
                    ->visibleFrom('lg')->toggleable(),
                Tables\Columns\TextColumn::make('credit_limit')
                    ->numeric(2)
                    ->sortable()->visibleFrom('lg')->toggleable(),
                Tables\Columns\TextColumn::make('ar_balance')->label('Balance')
                    ->state(fn (Customer $r) => $r->balance())->numeric(2),
                Tables\Columns\TextColumn::make('beginning_balance')->label('Beginning bal.')
                    ->state(fn (Customer $r) => $r->beginning_balance)->numeric(2)
                    ->visible(fn () => CustomerBalanceService::canManage(auth()->user()))
                    ->toggleable(),
                Tables\Columns\IconColumn::make('active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('Customer type')->options(CustomerType::OPTIONS),
                Tables\Filters\SelectFilter::make('customer_group_id')->label('Price group')->relationship('customerGroup', 'name'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('payOpening')->label('Pay beginning bal.')->icon('heroicon-o-banknotes')
                    ->visible(fn (Customer $r) => CustomerBalanceService::canManage(auth()->user())
                        && Money::of(app(CustomerBalanceService::class)->openingRemaining($r))->isPositive())
                    ->form([
                        Forms\Components\TextInput::make('amount')->numeric()->required()->step(0.01)->inputMode('decimal')->rule('decimal:0,2')
                            ->default(fn (Customer $r) => number_format((float) app(CustomerBalanceService::class)->openingRemaining($r), 2, '.', '')),
                        Forms\Components\Select::make('method')->options(['cash' => 'Cash', 'check' => 'Check', 'ach' => 'ACH', 'card' => 'Card (manual)'])->default('check')->required(),
                        Forms\Components\TextInput::make('reference'),
                    ])
                    ->action(function (Customer $r, array $data) {
                        app(CustomerBalanceService::class)->payOpening($r, round((float) $data['amount'], 2), $data['method'], $data['reference'] ?? null);
                        Notification::make()->title('Payment applied to beginning balance')->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->visible(fn () => static::canDeleteAny()),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        // 0.20.13 tab order: Customer Contacts, Ship To, Licenses, History (extras after History).
        return [
            CustomerResource\RelationManagers\ContactsRelationManager::class,
            CustomerResource\RelationManagers\ShipTosRelationManager::class,
            CustomerResource\RelationManagers\LicensesRelationManager::class,
            HistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
