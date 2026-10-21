<?php

namespace App\Filament\Resources;

use App\Accounting\ApService;
use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\VendorPaymentResource\Pages;
use App\Models\BankAccount;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorPayment;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** AP payments (Dr 2000 / Cr bank). Created with "Pay bills"; voiding reverses the journal and reopens the bills. */
class VendorPaymentResource extends Resource
{
    use RoleGated;

    protected static string $module = 'ap';

    protected static ?string $model = VendorPayment::class;

    protected static ?string $navigationGroup = 'Accounting';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Vendor payments';

    protected static ?int $navigationSort = 11;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function payForm(): array
    {
        return [
            Forms\Components\Select::make('vendor_id')->label('Vendor')->required()->live()->searchable()
                ->options(fn () => Vendor::whereIn('id', VendorBill::whereIn('status', ['posted', 'partial'])->select('vendor_id'))->orderBy('name')->pluck('name', 'id'))
                ->afterStateUpdated(fn (Forms\Set $set, $state) => $set('alloc', VendorBill::where('vendor_id', $state ?: 0)->whereIn('status', ['posted', 'partial'])
                    ->get()->mapWithKeys(fn (VendorBill $b) => [$b->id => $b->balance()])->all())),
            Forms\Components\Select::make('bank_account_id')->label('Pay from')->required()
                ->options(fn () => BankAccount::where('active', true)->pluck('name', 'id'))->default(fn () => BankAccount::default()?->id),
            Forms\Components\DatePicker::make('paid_on')->default(now())->required(),
            Forms\Components\Select::make('method')->options(['check' => 'Check', 'ach' => 'ACH', 'wire' => 'Wire', 'card' => 'Card', 'cash' => 'Cash'])->default('check')->required(),
            Forms\Components\TextInput::make('reference')->label('Check # / reference'),
            Forms\Components\Fieldset::make('Apply to bills')->columns(1)->schema(fn (Forms\Get $get) => VendorBill::where('vendor_id', $get('vendor_id') ?: 0)
                ->whereIn('status', ['posted', 'partial'])->orderBy('due_date')->get()
                ->map(fn (VendorBill $b) => Forms\Components\TextInput::make("alloc.{$b->id}")->numeric()->minValue(0)
                    ->label("{$b->number} · inv {$b->vendor_invoice} · due {$b->due_date->toDateString()} · open $".number_format((float) $b->balance(), 2)))
                ->all()),
        ];
    }

    public static function pay(array $data): ?VendorPayment
    {
        try {
            $p = app(ApService::class)->pay(Vendor::findOrFail($data['vendor_id']), BankAccount::findOrFail($data['bank_account_id']),
                $data['paid_on'], $data['alloc'] ?? [], $data['method'], $data['reference'] ?? null);
            Notification::make()->success()->title("Payment {$p->number} posted: $".number_format((float) $p->amount, 2))->send();

            return $p;
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Payment not posted')->body($e->getMessage())->persistent()->send();

            return null;
        }
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('number')->searchable(),
                Tables\Columns\TextColumn::make('vendor.name')->searchable(),
                Tables\Columns\TextColumn::make('paid_on')->date()->sortable(),
                Tables\Columns\TextColumn::make('method'),
                Tables\Columns\TextColumn::make('reference'),
                Tables\Columns\TextColumn::make('bankAccount.name')->label('From'),
                Tables\Columns\TextColumn::make('amount')->money('USD'),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('bills')->state(fn (VendorPayment $p) => $p->allocations()->with('bill')->get()->map(fn ($a) => $a->bill->number)->implode(', ')),
            ])
            ->actions([
                Tables\Actions\Action::make('void')->label('Void')->color('danger')->icon('heroicon-o-x-circle')
                    ->visible(fn (VendorPayment $p) => $p->status === 'posted' && static::allowed('manage'))
                    ->form([Forms\Components\TextInput::make('reason')->required()])
                    ->action(function (VendorPayment $p, array $data) {
                        try {
                            app(ApService::class)->voidPayment($p, $data['reason']);
                            Notification::make()->success()->title("Payment {$p->number} voided")->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title('Not voided')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListVendorPayments::route('/')];
    }
}
