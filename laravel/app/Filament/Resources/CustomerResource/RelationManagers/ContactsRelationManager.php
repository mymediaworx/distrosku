<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Models\CustomerContact;
use App\Models\ShipTo;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Portal contacts. No emails are sent: "Set-password link" shows a one-time link (72 h) for the admin to copy and
 * hand to the contact. Issuing a new link also serves as the password reset.
 */
class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $title = 'Portal contacts';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(190),
            Forms\Components\TextInput::make('email')->email()->required()->maxLength(190)
                ->unique(CustomerContact::class, 'email', ignoreRecord: true),
            Forms\Components\TextInput::make('phone')->tel()->maxLength(30),
            Forms\Components\Select::make('ship_to_id')->label('Restrict to ship-to (optional)')
                ->options(fn () => ShipTo::where('customer_id', $this->getOwnerRecord()->id)->get()->mapWithKeys(fn ($s) => [$s->id => "{$s->name} ({$s->state})"]))
                ->placeholder('All ship-tos'),
            Forms\Components\Toggle::make('active')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('email')->searchable(),
                Tables\Columns\TextColumn::make('shipTo.name')->label('Ship-to')->placeholder('All'),
                Tables\Columns\IconColumn::make('active')->boolean(),
                Tables\Columns\TextColumn::make('password')->label('Password set')->formatStateUsing(fn ($state) => $state ? 'yes' : 'no')->placeholder('no'),
                Tables\Columns\TextColumn::make('last_login_at')->dateTime()->placeholder('never'),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()->label('Add contact')])
            ->actions([
                Tables\Actions\Action::make('link')->label('Set-password link')->icon('heroicon-o-link')
                    ->requiresConfirmation()
                    ->modalDescription('Creates a one-time link (valid '.CustomerContact::LINK_HOURS.' hours) and invalidates any earlier link. Nothing is emailed: copy the link and give it to the contact.')
                    ->action(function (CustomerContact $record) {
                        $url = $record->issueSetupLink();
                        Notification::make()->success()->persistent()->title('One-time set-password link (copy now, shown once)')
                            ->body($url)->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
