<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\PaymentResource\Pages;
use App\Models\Payment;
use App\Services\PaymentService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PaymentResource extends Resource
{
    use RoleGated;

    protected static string $module = 'payments';

    protected static ?string $model = Payment::class;

    protected static ?string $navigationGroup = 'Sales';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    // Read-only ledger: entries come from services, never typed in.
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

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('customer_id')->relationship('customer', 'name')->searchable()->preload()->required(),
                Forms\Components\Select::make('sales_order_id')->relationship('salesOrder', 'number')->searchable()->preload(),
                Forms\Components\TextInput::make('amount')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('method')
                    ->required(),
                Forms\Components\TextInput::make('reference'),
                Forms\Components\DatePicker::make('paid_on')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')
                    ->sortable(),
                Tables\Columns\TextColumn::make('salesOrder.number')
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('kind')->badge(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\IconColumn::make('applies_to_opening')->label('Beg. bal.')->boolean()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('method')
                    ->searchable(),
                Tables\Columns\TextColumn::make('reference')
                    ->searchable(),
                Tables\Columns\TextColumn::make('paid_on')
                    ->date()
                    ->sortable(),
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
                Tables\Actions\Action::make('refund')->label('Refund')->icon('heroicon-o-arrow-uturn-left')->color('warning')
                    ->visible(fn (Payment $r) => $r->kind === 'payment' && $r->status === 'posted' && $r->provider_transaction_id && auth()->user()->canModule('refunds', 'manage'))
                    ->form([Forms\Components\TextInput::make('amount')->numeric()->required()->default(fn (Payment $r) => $r->amount)])
                    ->requiresConfirmation()
                    ->action(fn (Payment $r, array $data) => self::gatewayAction(fn () => app(PaymentService::class)->refund($r, $data['amount']), 'Refunded')),
                Tables\Actions\Action::make('void')->label('Void')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (Payment $r) => $r->kind === 'payment' && $r->status === 'posted' && $r->provider_transaction_id && auth()->user()->canModule('refunds', 'manage'))
                    ->requiresConfirmation()
                    ->action(fn (Payment $r) => self::gatewayAction(fn () => app(PaymentService::class)->void($r), 'Voided')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\RelationManagers\HistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
            'create' => Pages\CreatePayment::route('/create'),
            'edit' => Pages\EditPayment::route('/{record}/edit'),
        ];
    }

    private static function gatewayAction(callable $fn, string $ok): void
    {
        try {
            $fn();
            Notification::make()->success()->title($ok)->send();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Gateway action failed')->body($e->getMessage())->persistent()->send();
        }
    }
}
