<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Exceptions\WorkflowException;
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
 * Customer contacts. Portal access is opt-in (is_portal_contact) and requires an email.
 * "Set-password link" shows a one-time link (72 h) for the admin to copy — nothing is emailed.
 */
class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $title = 'Customer Contacts';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(190),
            Forms\Components\TextInput::make('email')->email()->maxLength(190)
                ->nullable()
                ->unique(CustomerContact::class, 'email', ignoreRecord: true)
                ->live(onBlur: true)
                ->afterStateUpdated(function ($state, Forms\Set $set) {
                    if (blank($state)) {
                        $set('is_portal_contact', false);
                    }
                }),
            Forms\Components\TextInput::make('phone')->tel()->maxLength(30),
            Forms\Components\Select::make('ship_to_id')->label('Restrict to ship-to (optional)')
                ->options(fn () => ShipTo::where('customer_id', $this->getOwnerRecord()->id)->get()->mapWithKeys(fn ($s) => [$s->id => "{$s->name} ({$s->state})"]))
                ->placeholder('All ship-tos'),
            Forms\Components\Toggle::make('active')->default(true),
            Forms\Components\Toggle::make('is_billing_contact')->label('Billing / AR contact')->default(false)
                ->helperText('Overdue (dunning) emails go to billing contacts that have an email; if none, to the customer email, then the first contact with an email.'),
            Forms\Components\Toggle::make('is_portal_contact')->label('Portal contact')->default(false)
                ->helperText('Portal access requires an email address.')
                ->disabled(fn (Forms\Get $get) => blank($get('email')))
                ->dehydrated()
                ->live(),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public static function mutateContactData(array $data): array
    {
        $email = trim((string) ($data['email'] ?? ''));
        if ($email === '') {
            $data['email'] = null;
            $data['is_portal_contact'] = false;
        }
        if (! empty($data['is_portal_contact']) && blank($data['email'] ?? null)) {
            throw new WorkflowException('Portal access requires an email address.');
        }

        return $data;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('email')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('shipTo.name')->label('Ship-to')->placeholder('All'),
                Tables\Columns\IconColumn::make('is_portal_contact')->label('Portal')->boolean(),
                Tables\Columns\IconColumn::make('is_billing_contact')->label('Billing')->boolean(),
                Tables\Columns\IconColumn::make('active')->boolean(),
                Tables\Columns\TextColumn::make('password')->label('Password set')->formatStateUsing(fn ($state) => $state ? 'yes' : 'no')->placeholder('no'),
                Tables\Columns\TextColumn::make('last_login_at')->dateTime()->placeholder('never'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Add contact')
                    ->mutateFormDataUsing(fn (array $data) => self::mutateContactData($data)),
            ])
            ->actions([
                Tables\Actions\Action::make('link')->label('Set-password link')->icon('heroicon-o-link')
                    ->visible(fn (CustomerContact $record) => $record->canInviteToPortal())
                    ->requiresConfirmation()
                    ->modalDescription('Creates a one-time link (valid '.CustomerContact::LINK_HOURS.' hours) and invalidates any earlier link. Nothing is emailed: copy the link and give it to the contact.')
                    ->action(function (CustomerContact $record) {
                        $url = $record->issueSetupLink();
                        Notification::make()->success()->persistent()->title('One-time set-password link (copy now, shown once)')
                            ->body($url)->send();
                    }),
                Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(fn (array $data) => self::mutateContactData($data)),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
