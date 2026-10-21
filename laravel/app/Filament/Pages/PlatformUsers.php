<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Filament\Concerns\PlatformOnly;
use App\Models\User;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class PlatformUsers extends Page implements HasTable
{
    use InteractsWithTable, PlatformOnly;

    protected static string $view = 'filament.pages.table-page';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $title = 'All users';

    protected static ?string $slug = 'platform/users';

    public function table(Table $table): Table
    {
        return $table->query(User::query())->columns([
            Tables\Columns\TextColumn::make('name')->searchable(),
            Tables\Columns\TextColumn::make('email')->searchable(),
            Tables\Columns\TextColumn::make('role')->badge()->formatStateUsing(fn ($state) => $state?->value),
            Tables\Columns\IconColumn::make('active')->boolean(),
        ])->headerActions([
            Tables\Actions\Action::make('create')->label('Create user')->icon('heroicon-o-user-plus')
                ->form([
                    Forms\Components\TextInput::make('name')->required(),
                    Forms\Components\TextInput::make('email')->email()->required()->unique('users', 'email'),
                    Forms\Components\Select::make('role')->options(Role::options())->required()->default('sales'),
                    Forms\Components\TextInput::make('password')->password()->required()->minLength(12),
                ])
                ->action(function (array $data) {
                    User::create($data + ['active' => true]);
                    Notification::make()->success()->title('User created')->send();
                }),
        ])->actions([
            Tables\Actions\Action::make('resetPassword')->label('Reset password')->icon('heroicon-o-key')
                ->form([Forms\Components\TextInput::make('password')->label('New password')->password()->required()->minLength(12)->confirmed(),
                    Forms\Components\TextInput::make('password_confirmation')->password()->required()])
                ->action(function (User $r, array $data) {
                    $r->forceFill(['password' => $data['password'], 'remember_token' => null])->save();
                    if (config('session.driver') === 'database') {
                        DB::table(config('session.table', 'sessions'))->where('user_id', $r->id)->delete();
                    }
                    Notification::make()->success()->title("Password reset for {$r->email}")->send();
                }),
            Tables\Actions\Action::make('toggle')->label(fn (User $r) => $r->active ? 'Deactivate' : 'Activate')
                ->visible(fn (User $r) => $r->id !== auth()->id())->requiresConfirmation()
                ->action(fn (User $r) => $r->update(['active' => ! $r->active])),
        ]);
    }
}
