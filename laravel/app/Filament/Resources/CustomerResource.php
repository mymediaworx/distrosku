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
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CustomerResource extends Resource
{
    /** 0.17.3: only owner / admin / brandworx_admin may delete customers. */
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
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

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->required(),
                Forms\Components\TextInput::make('name')
                    ->required(),
                Forms\Components\TextInput::make('type')
                    ->required(),
                Forms\Components\TextInput::make('email')
                    ->email(),
                Forms\Components\TextInput::make('phone')
                    ->tel(),
                Forms\Components\TextInput::make('terms')
                    ->required(),
                Forms\Components\Select::make('customer_group_id')->label('Price group')->relationship('customerGroup', 'name')->searchable()->preload()->nullable()
                    ->helperText('Group prices and group promotions apply to this customer.'),
                Forms\Components\Select::make('sales_rep_id')->label('Sales rep')->relationship('salesRep', 'name')->searchable()->preload()->nullable(),
                Forms\Components\TextInput::make('credit_limit')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->disabled(fn () => ! auth()->user()?->isAdmin())
                    ->dehydrated(fn () => (bool) auth()->user()?->isAdmin())
                    ->helperText(fn () => (auth()->user()?->isAdmin() ? '' : 'Only an owner/admin can change the credit limit. ').'0 = no limit. Charges to the account that would go over the limit are blocked (owner/admin can override per order).'),
                Forms\Components\Placeholder::make('available_credit')->label('Available credit')
                    ->visible(fn (?Customer $record) => $record !== null)
                    ->content(fn (?Customer $record) => $record ? CreditLimit::summary($record) : ''),
                Forms\Components\CheckboxList::make('delivery_days')->options(DeliveryDays::DAYS)->columns(7)->gridDirection('row')
                    ->formatStateUsing(fn ($state) => DeliveryDays::parse($state))->dehydrateStateUsing(fn ($state) => DeliveryDays::toCsv($state))->columnSpanFull(),
                Forms\Components\TextInput::make('state_tax_id')->label('State tax ID')->maxLength(40)
                    ->helperText('Sent to BrandWorx Marketing (State Tax ID custom field).'),
                Forms\Components\Toggle::make('email_opt_in')->label('Email opt-in (marketing / notices)')
                    ->helperText('Drives email consent in BrandWorx Marketing. Off sets Email to DND there.'),
                Forms\Components\Toggle::make('sms_opt_in')->label('SMS opt-in')
                    ->helperText('Only with recorded consent (A2P 10DLC). Drives SMS consent in BrandWorx Marketing. Off sets SMS to DND there.'),
                Forms\Components\Toggle::make('active')
                    ->required(),
                Forms\Components\Fieldset::make('MSA')->visible(fn () => (bool) auth()->user()?->isAdmin())->schema([
                    Forms\Components\Select::make('msa_class_of_trade')->label('Class of trade (blank = from type)')
                        ->options(['Retailer' => 'Retailer', 'Distributor' => 'Distributor', 'Other' => 'Other']),
                    Forms\Components\Toggle::make('msa_promo_acceptance')->label('Accepts promotional tobacco product'),
                ])->columns(2),
                // Admin-level only (owner/admin/brandworx_admin). Not dehydrated: saved via CustomerBalanceService (ledger + audit).
                Forms\Components\Section::make('Beginning balance (migrated from previous system)')
                    ->visible(fn () => CustomerBalanceService::canManage(auth()->user()))
                    ->schema([
                        Forms\Components\TextInput::make('beginning_balance')->numeric()->default(0)
                            ->helperText('Negative = customer credit. Changes post Dr/Cr 1100 AR vs 3900 Opening Balance Equity for the difference.')
                            ->dehydrated(false),
                        Forms\Components\DatePicker::make('beginning_balance_date')->dehydrated(false),
                        Forms\Components\TextInput::make('beginning_balance_note')->label('Reason for change')->dehydrated(false),
                    ])->columns(3),
                Forms\Components\Section::make('Balance & aging')
                    ->visible(fn (?Customer $record) => (bool) $record?->exists)
                    ->schema([
                        Forms\Components\Placeholder::make('aging')->hiddenLabel()
                            ->content(fn (?Customer $record) => $record ? view('docs.aging-inline', ['aging' => app(CustomerBalanceService::class)->aging($record)]) : null),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone')
                    ->searchable(),
                Tables\Columns\TextColumn::make('terms')
                    ->searchable(),
                Tables\Columns\TextColumn::make('credit_limit')
                    ->numeric()
                    ->sortable(),
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
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('payOpening')->label('Pay beginning bal.')->icon('heroicon-o-banknotes')
                    ->visible(fn (Customer $r) => CustomerBalanceService::canManage(auth()->user())
                        && Money::of(app(CustomerBalanceService::class)->openingRemaining($r))->isPositive())
                    ->form([
                        Forms\Components\TextInput::make('amount')->numeric()->required()
                            ->default(fn (Customer $r) => app(CustomerBalanceService::class)->openingRemaining($r)),
                        Forms\Components\Select::make('method')->options(['cash' => 'Cash', 'check' => 'Check', 'ach' => 'ACH', 'card' => 'Card (manual)'])->default('check')->required(),
                        Forms\Components\TextInput::make('reference'),
                    ])
                    ->action(function (Customer $r, array $data) {
                        app(CustomerBalanceService::class)->payOpening($r, $data['amount'], $data['method'], $data['reference'] ?? null);
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
        return [
            CustomerResource\RelationManagers\ShipTosRelationManager::class,
            CustomerResource\RelationManagers\ContactsRelationManager::class,
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
