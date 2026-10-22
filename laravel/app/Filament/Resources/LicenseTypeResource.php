<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LicenseTypeResource\Pages;
use App\Models\CustomerLicense;
use App\Models\LicenseType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** 0.20.6: Settings › License types — the options for a customer license's Type. */
class LicenseTypeResource extends Resource
{
    protected static ?string $model = LicenseType::class;

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'License types';

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'settings/license-types';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    /** A type that licenses use cannot be deleted (turn it off instead). */
    public static function canDelete(Model $record): bool
    {
        return static::canViewAny() && ! CustomerLicense::where('type', $record->code)->exists();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(80)->placeholder('KY Tobacco License'),
            Forms\Components\TextInput::make('code')->required()->maxLength(40)->unique(ignoreRecord: true)
                ->disabled(fn (?LicenseType $record) => $record !== null)->dehydrated(fn (?LicenseType $record) => $record === null)
                ->helperText('Stored on licenses and used by compliance rules. Cannot change after creation.')
                ->dehydrateStateUsing(fn ($state) => \Illuminate\Support\Str::snake(trim((string) $state))),
            Forms\Components\TextInput::make('state')->maxLength(2)->helperText('Default state for new licenses of this type (optional).')
                ->dehydrateStateUsing(fn ($state) => $state ? strtoupper(trim($state)) : null),
            Forms\Components\TextInput::make('sort')->integer()->default(100),
            Forms\Components\Toggle::make('active')->default(true)->inline(false),
        ])->columns(['default' => 1, 'md' => 2]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->columns([
            Tables\Columns\TextColumn::make('name')->searchable(),
            Tables\Columns\TextColumn::make('code')->badge()->color('gray')->visibleFrom('sm'),
            Tables\Columns\TextColumn::make('state')->placeholder('—'),
            Tables\Columns\TextColumn::make('in_use')->label('Licenses')
                ->state(fn (LicenseType $r) => CustomerLicense::where('type', $r->code)->count()),
            Tables\Columns\IconColumn::make('active')->boolean(),
        ])->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLicenseTypes::route('/'),
            'create' => Pages\CreateLicenseType::route('/create'),
            'edit' => Pages\EditLicenseType::route('/{record}/edit'),
        ];
    }
}
