<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\CarrierResource\Pages;
use App\Models\Carrier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CarrierResource extends Resource
{
    use RoleGated;

    protected static string $module = 'carriers';

    protected static ?string $model = Carrier::class;

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('code')->required()->maxLength(20)->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('name')->required(),
            Forms\Components\TextInput::make('address')->helperText('Shown on PACT reports as the person delivering.'),
            Forms\Components\TextInput::make('phone'),
            Forms\Components\TextInput::make('tracking_url')->label('Tracking link')->helperText('Use {tracking} where the number goes.')->columnSpanFull()
                ->rule(fn () => function ($attr, $value, $fail) {
                    if ($value && (! str_starts_with($value, 'https://') || ! str_contains($value, '{tracking}'))) {
                        $fail('Must start with https:// and contain {tracking}.');
                    }
                }),
            Forms\Components\Toggle::make('is_postal')->label('Postal service (blocks tobacco / ENDS, 18 U.S.C. 1716E)'),
            Forms\Components\Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('code'), Tables\Columns\TextColumn::make('name'), Tables\Columns\TextColumn::make('phone'),
            Tables\Columns\IconColumn::make('is_postal')->boolean()->label('Postal'), Tables\Columns\IconColumn::make('active')->boolean(),
        ])->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCarriers::route('/'), 'create' => Pages\CreateCarrier::route('/create'), 'edit' => Pages\EditCarrier::route('/{record}/edit')];
    }
}
