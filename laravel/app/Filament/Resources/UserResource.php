<?php

namespace App\Filament\Resources;

use App\Enums\Role;
use App\Exceptions\WorkflowException;
use App\Filament\Concerns\RoleGated;
use App\Filament\RelationManagers\HistoryRelationManager;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Pos\PosPin;
use App\Security\PasswordAdmin;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    use RoleGated;

    protected static string $module = 'users';

    protected static ?string $model = User::class;

    protected static ?string $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    /** Only the owner may edit an owner; only a BrandWorx admin may edit a BrandWorx admin account (0.17.2). */
    public static function canEdit(Model $record): bool
    {
        $me = auth()->user();
        if (! static::allowed('manage')) {
            return false;
        }
        if ($record->role === Role::BrandworxAdmin) {
            return $me->hasRole(Role::BrandworxAdmin); // 0.17.2: only a BrandWorx admin
        }
        if ($record->role === Role::Owner) {
            return $me->isOwner() || $me->is($record);
        }

        return true;
    }

    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record) && ! auth()->user()->is($record) && $record->role !== Role::Owner;
    }

    /** Non-owners cannot hand out owner; only a BrandWorx admin can hand out brandworx_admin (0.17.3). */
    public static function roleOptions(): array
    {
        $opts = Role::options();
        if (! auth()->user()?->isOwner()) {
            unset($opts['owner']);
        }
        if (! auth()->user()?->hasRole(Role::BrandworxAdmin)) {
            unset($opts['brandworx_admin']); // 0.17.3: only a BrandWorx admin can assign it
        }

        return $opts;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->required(),
                TextInput::make('email')
                    ->email()
                    ->required(),
                Forms\Components\DateTimePicker::make('email_verified_at'),
                TextInput::make('password')->password()->revealable()->dehydrated(fn ($state) => filled($state))->required(fn (string $operation) => $operation === 'create')->minLength(12),
                Forms\Components\Select::make('role')->options(fn (?User $record) => $record && ! array_key_exists($record->role->value, static::roleOptions()) ? [$record->role->value => Role::options()[$record->role->value]] + static::roleOptions() : static::roleOptions())->required()->default('sales')
                    ->in(fn (?User $record) => array_keys(($record ? [$record->role->value => 1] : []) + static::roleOptions())),
                Forms\Components\Toggle::make('active')
                    ->default(true) // 0.17.1: new users are active unless switched off
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email_verified_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('role')->badge()->formatStateUsing(fn ($state) => $state->value),
                Tables\Columns\IconColumn::make('must_change_password')->label('Must change pw')->boolean()->toggleable(),
                Tables\Columns\IconColumn::make('active')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('setPin')->label('Set POS PIN')->icon('heroicon-o-key')
                    ->visible(fn () => auth()->user()->canModule('users', 'manage'))
                    ->form([TextInput::make('pin')->label('4-digit PIN')->password()->required()->regex('/^\d{4}$/')])
                    ->action(function (User $record, array $data) {
                        try {
                            app(PosPin::class)->set($record, $data['pin'], auth()->user());
                            Notification::make()->success()->title("POS PIN set for {$record->name}")->send();
                        } catch (WorkflowException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
                Tables\Actions\Action::make('clearPin')->label('Clear POS PIN')->icon('heroicon-o-no-symbol')->color('danger')->requiresConfirmation()
                    ->visible(fn (User $record) => $record->pos_pin_hash && auth()->user()->canModule('users', 'manage'))
                    ->action(fn (User $record) => app(PosPin::class)->clear($record)),
                Tables\Actions\Action::make('resetPassword')->label('Reset password')->icon('heroicon-o-lock-open')->color('warning')
                    ->visible(fn (User $r) => PasswordAdmin::canReset(auth()->user(), $r))
                    ->modalDescription('No two-factor. The user is signed out of other sessions.')
                    ->form(fn () => [
                        Forms\Components\Radio::make('mode')->label('Method')->required()->default('temp')->live()
                            ->options(array_filter(['temp' => 'Set a temporary password', 'link' => PasswordAdmin::mailConfigured() ? 'Email a reset link' : null])),
                        TextInput::make('password')->label('Temporary password')->default(fn () => PasswordAdmin::generate())
                            ->required(fn (Forms\Get $get) => $get('mode') === 'temp')->minLength(PasswordAdmin::MIN)
                            ->visible(fn (Forms\Get $get) => $get('mode') === 'temp')->helperText('Give it to the user in person or by phone; it is not shown again.'),
                        Forms\Components\Toggle::make('force')->label('Must change password at next sign-in')->default(true),
                    ])
                    ->action(function (User $record, array $data) {
                        try {
                            $svc = app(PasswordAdmin::class);
                            if (($data['mode'] ?? 'temp') === 'link') {
                                $svc->sendLink(auth()->user(), $record, (bool) ($data['force'] ?? false));
                                Notification::make()->success()->title("Reset link sent to {$record->email}")->send();
                            } else {
                                $svc->setTemporary(auth()->user(), $record, (string) $data['password'], (bool) ($data['force'] ?? true));
                                Notification::make()->success()->title("Temporary password set for {$record->name}")->send();
                            }
                        } catch (WorkflowException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
                Tables\Actions\Action::make('toggleActive')
                    ->label(fn (User $r) => $r->active ? 'Deactivate' : 'Activate')
                    ->icon(fn (User $r) => $r->active ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
                    ->color(fn (User $r) => $r->active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->visible(fn (User $r) => ! auth()->user()->is($r) && $r->role !== Role::Owner
                        && ($r->role === Role::BrandworxAdmin ? auth()->user()->hasRole(Role::BrandworxAdmin) : static::canEdit($r)))
                    ->action(fn (User $r) => $r->update(['active' => ! $r->active])),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            HistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
