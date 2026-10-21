<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\DepartmentResource\Pages;
use App\Models\Department;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DepartmentResource extends Resource
{
    use RoleGated;

    protected static string $module = 'departments';

    protected static ?string $model = Department::class;

    protected static ?string $navigationGroup = 'Catalog';

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 2;

    public static function canEdit(Model $record): bool
    {
        return static::allowed('manage');
    }

    public static function canDelete(Model $record): bool
    {
        return static::allowed('manage') && ! $record->products()->exists();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('code')->required()->maxLength(10)->regex('/^[A-Za-z0-9]{2,10}$/')
                ->unique(ignoreRecord: true)->helperText('2-10 letters/digits; SKUs become CODE-0001.')
                ->disabled(fn (?Department $record) => $record?->products()->exists())->dehydrated(fn (?Department $record) => ! $record?->products()->exists()),
            Forms\Components\TextInput::make('name')->required()->maxLength(255),
            Forms\Components\Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('code')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('name')->searchable(),
            Tables\Columns\TextColumn::make('products_count')->counts('products')->label('SKUs'),
            Tables\Columns\IconColumn::make('active')->boolean(),
        ])->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDepartments::route('/'),
            'create' => Pages\CreateDepartment::route('/create'),
            'edit' => Pages\EditDepartment::route('/{record}/edit'),
        ];
    }
}
