<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\PosPinLoginResource\Pages;
use App\Models\PosPinLogin;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Read-only POS PIN log: logins, wrong PINs, lockouts, PIN changes. */
class PosPinLoginResource extends Resource
{
    use RoleGated;

    protected static string $module = 'users';

    protected static ?string $model = PosPinLogin::class;

    protected static ?string $modelLabel = 'POS PIN login';
    protected static ?string $pluralModelLabel = 'POS PIN log'; // page title matches the sidebar (UI audit #6)

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'POS PIN log';

    protected static ?string $navigationIcon = 'heroicon-o-finger-print';

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

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            Tables\Columns\TextColumn::make('created_at')->dateTime()->label('When'),
            Tables\Columns\TextColumn::make('user.name')->label('Employee')->searchable(),
            Tables\Columns\TextColumn::make('event')->badge()->color(fn ($state) => match ($state) {
                'success' => 'success', 'failed', 'locked' => 'danger', default => 'gray'
            }),
            Tables\Columns\TextColumn::make('ip'),
            Tables\Columns\TextColumn::make('user_agent')->limit(40)->toggleable(isToggledHiddenByDefault: true),
        ])->filters([Tables\Filters\SelectFilter::make('event')->options(['success' => 'Login', 'failed' => 'Wrong PIN', 'locked' => 'Locked out', 'pin_set' => 'PIN set'])]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPosPinLogins::route('/')];
    }
}
