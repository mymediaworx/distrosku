<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Models\LicenseType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** 0.20.6: customer licenses on the customer page. Type comes from Settings › License types (code stored; compliance rules match on it). */
class LicensesRelationManager extends RelationManager
{
    protected static string $relationship = 'licenses';

    protected static ?string $title = 'Licenses';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->canModule('customer_licenses');
    }

    private static function canManage(): bool
    {
        return (bool) auth()->user()?->canModule('customer_licenses', 'manage');
    }

    protected function canCreate(): bool
    {
        return self::canManage();
    }

    protected function canEdit(Model $record): bool
    {
        return self::canManage();
    }

    protected function canDelete(Model $record): bool
    {
        return self::canManage();
    }

    public static function licenseFields(bool $withCustomer = false): array
    {
        return array_values(array_filter([
            $withCustomer ? Forms\Components\Select::make('customer_id')->relationship('customer', 'name')->searchable()->preload()->required() : null,
            Forms\Components\Select::make('type')->label('License type')
                ->options(fn (?Model $record) => LicenseType::options($record?->type))->required()->searchable()
                ->live()->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                    if ($state && ! $get('state') && ($st = LicenseType::where('code', $state)->value('state'))) {
                        $set('state', $st);
                    }
                })
                ->helperText('Manage the list in Settings › License types.'),
            Forms\Components\TextInput::make('number')->label('License #')->required(),
            Forms\Components\TextInput::make('state')->required()->maxLength(2)->default('KY')
                ->dehydrateStateUsing(fn ($state) => strtoupper(trim((string) $state))),
            Forms\Components\DatePicker::make('expires_at')->label('Expires'),
            $withCustomer
                ? Forms\Components\Select::make('ship_to_id')->label('Ship-to (blank = all)')->relationship('shipTo', 'name')->searchable()->preload()
                : Forms\Components\Select::make('ship_to_id')->label('Ship-to (blank = all)')
                    ->options(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->shipTos()->pluck('name', 'id'))->searchable(),
        ]));
    }

    public function form(Form $form): Form
    {
        return $form->schema(self::licenseFields())->columns(['default' => 1, 'md' => 2]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->columns([
                Tables\Columns\TextColumn::make('type')->label('Type')->badge()
                    ->formatStateUsing(fn ($state) => LicenseType::label($state)),
                Tables\Columns\TextColumn::make('number')->label('License #')->searchable(),
                Tables\Columns\TextColumn::make('state')->visibleFrom('sm'),
                Tables\Columns\TextColumn::make('shipTo.name')->label('Ship-to')->placeholder('All')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('expires_at')->label('Expires')->date()->sortable()
                    ->color(fn ($record) => $record->expires_at && $record->expires_at->isPast() ? 'danger'
                        : ($record->expires_at && $record->expires_at->lte(now()->addDays(30)) ? 'warning' : null))
                    ->placeholder('No expiry'),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()->label('Add license')])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->emptyStateHeading('No licenses on file');
    }
}
