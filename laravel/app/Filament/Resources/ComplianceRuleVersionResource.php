<?php

namespace App\Filament\Resources;

use App\Enums\TaxClass;
use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\ComplianceRuleVersionResource\Pages;
use App\Models\ComplianceRuleVersion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ComplianceRuleVersionResource extends Resource
{
    use RoleGated;

    protected static string $module = 'compliance_rules';

    protected static ?string $model = ComplianceRuleVersion::class;

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('jurisdiction')
                    ->required(),
                Forms\Components\Select::make('tax_class')->options(TaxClass::options()),
                Forms\Components\TextInput::make('hemp_type'),
                Forms\Components\Select::make('rule')->options(['ban' => 'Ban', 'thc_limit' => 'THC limit (mg)', 'license_required' => 'License required'])->required(),
                Forms\Components\Toggle::make('banned')
                    ->required(),
                Forms\Components\TextInput::make('max_thc_mg')
                    ->numeric(),
                Forms\Components\TextInput::make('license_type'),
                Forms\Components\DatePicker::make('effective_from')
                    ->required(),
                Forms\Components\DatePicker::make('effective_to'),
                Forms\Components\TextInput::make('version')
                    ->required(),
                Forms\Components\TextInput::make('source'),
                Forms\Components\Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('jurisdiction')
                    ->searchable(),
                Tables\Columns\TextColumn::make('tax_class')->formatStateUsing(fn ($state) => $state?->label()),
                Tables\Columns\TextColumn::make('hemp_type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('rule')
                    ->searchable(),
                Tables\Columns\IconColumn::make('banned')
                    ->boolean(),
                Tables\Columns\TextColumn::make('max_thc_mg')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('license_type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('effective_from')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('effective_to')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('version')
                    ->searchable(),
                Tables\Columns\TextColumn::make('source')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListComplianceRuleVersions::route('/'),
            'create' => Pages\CreateComplianceRuleVersion::route('/create'),
            'edit' => Pages\EditComplianceRuleVersion::route('/{record}/edit'),
        ];
    }
}
