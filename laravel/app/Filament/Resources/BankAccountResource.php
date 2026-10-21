<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Pages\BankReconciliation;
use App\Filament\Resources\BankAccountResource\Pages;
use App\Models\Account;
use App\Models\BankAccount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BankAccountResource extends Resource
{
    use RoleGated;

    protected static string $module = 'bank';

    protected static ?string $model = BankAccount::class;

    protected static ?string $navigationGroup = 'Accounting';

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?int $navigationSort = 15;

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required(),
            Forms\Components\Select::make('account_id')->label('GL cash account')->required()
                ->options(fn () => Account::where('type', 'asset')->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->id => "{$a->code} {$a->name}"])),
            Forms\Components\TextInput::make('bank_name'),
            Forms\Components\TextInput::make('last4')->label('Last 4 digits')->maxLength(4)->regex('/^\d{0,4}$/'),
            Forms\Components\DatePicker::make('cleared_through')->label('Treat GL entries on or before this date as cleared')
                ->helperText('Set this to the last statement date you reconciled outside the ERP, so older entries do not show as outstanding.'),
            Forms\Components\Toggle::make('is_default')->label('Default for payments'),
            Forms\Components\Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name'),
            Tables\Columns\TextColumn::make('account.code')->label('GL'),
            Tables\Columns\TextColumn::make('bank_name'),
            Tables\Columns\TextColumn::make('last4')->label('Last 4'),
            Tables\Columns\TextColumn::make('cleared_through')->date(),
            Tables\Columns\IconColumn::make('is_default')->boolean(),
            Tables\Columns\IconColumn::make('active')->boolean(),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\Action::make('reconcile')->label('Reconcile')->icon('heroicon-o-check-badge')
                ->url(fn (BankAccount $r) => BankReconciliation::getUrl(['bank' => $r->id])),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBankAccounts::route('/'),
            'create' => Pages\CreateBankAccount::route('/create'),
            'edit' => Pages\EditBankAccount::route('/{record}/edit'),
        ];
    }
}
