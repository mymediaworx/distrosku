<?php

namespace App\Filament\Resources;

use App\Enums\TaxClass;
use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\EligibilityRuleResource\Pages;
use App\Models\EligibilityRule;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** 0.18: product eligibility registry (Compliance > Eligibility rules). */
class EligibilityRuleResource extends Resource
{
    use RoleGated;

    protected static string $module = 'compliance_rules';

    protected static ?string $model = EligibilityRule::class;

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Eligibility rules';

    protected static ?string $modelLabel = 'eligibility rule';

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    /** Built-in rules (msa_directory, federal_hemp_2026) can be edited or switched off, not deleted. */
    public static function canDelete(Model $record): bool
    {
        return static::allowed('manage') && blank($record->key);
    }

    public static function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            Forms\Components\Placeholder::make('counsel')->hiddenLabel()->columnSpanFull()
                ->visible(fn (Get $get) => (bool) $get('verify_with_counsel'))
                ->content('Verify with counsel: this rule is a placeholder. Do not enable it until the thresholds and scope are confirmed.'),
            Forms\Components\TextInput::make('name')->required()->maxLength(190)->columnSpan(2),
            Forms\Components\Select::make('action')->options(['block' => 'Block the sale', 'warn' => 'Warn only'])->required()->default('block'),
            Forms\Components\Section::make('Jurisdiction')->columns(4)->schema([
                Forms\Components\Select::make('level')->options(['federal' => 'Federal', 'state' => 'State / local'])->required()->default('state')->live(),
                Forms\Components\TextInput::make('state')->length(2)->helperText('Blank = every state')->visible(fn (Get $get) => $get('level') === 'state')
                    ->dehydrateStateUsing(fn ($state) => $state ? strtoupper($state) : null),
                Forms\Components\TextInput::make('county')->maxLength(100)->visible(fn (Get $get) => $get('level') === 'state')->helperText('Optional; matches the ship-to county'),
                Forms\Components\TextInput::make('city')->maxLength(100)->visible(fn (Get $get) => $get('level') === 'state')->helperText('Optional; matches the ship-to city'),
            ]),
            Forms\Components\Section::make('Products it looks at')->columns(2)->description('Blank = all products.')->schema([
                Forms\Components\Select::make('subtype')->options(Product::SUBTYPES)->placeholder('Any subtype'),
                Forms\Components\Select::make('tax_class')->options(TaxClass::options())->placeholder('Any tax class'),
                Forms\Components\Select::make('selector')->label('Only')->options(\App\Compliance\Eligibility::SELECTORS)->placeholder('No extra filter'),
            ]),
            Forms\Components\Section::make('Fires when any of these is true')->columns(3)
                ->description('Leave every condition blank to apply to all matched products (a ban). Thresholds are configurable; enter them only from the official text / counsel.')->schema([
                    Forms\Components\TextInput::make('thc_total_max_mg')->label('Max total THC per container (mg)')->numeric()->minValue(0),
                    Forms\Components\TextInput::make('thc_serving_max_mg')->label('Max THC per serving (mg)')->numeric()->minValue(0),
                    Forms\Components\Toggle::make('thc_unknown_fires')->label('Also fire when the SKU has no THC figure'),
                    Forms\Components\Toggle::make('synthetic_fires')->label('Synthetic / converted cannabinoid'),
                    Forms\Components\Toggle::make('flavored_fires')->label('Flavored (flavor ban)'),
                    Forms\Components\CheckboxList::make('fda_statuses')->label('FDA status is one of')->options(Product::FDA_STATUSES)->columns(2),
                    Forms\Components\Toggle::make('needs_vape_directory')->label('Not on the ship-to state vape directory'),
                    Forms\Components\Toggle::make('needs_msa_directory')->label('Brand not on the state MSA directory'),
                    Forms\Components\Toggle::make('needs_current_coa')->label('No current COA on file'),
                ]),
            Forms\Components\DatePicker::make('effective_from')->required(),
            Forms\Components\DatePicker::make('effective_to')->afterOrEqual('effective_from'),
            Forms\Components\Placeholder::make('version')->content(fn (?EligibilityRule $record) => $record ? 'v'.$record->version.' (bumps on each change)' : 'v1'),
            Forms\Components\Toggle::make('enabled')->helperText('Off = draft: not enforced, can be previewed in Inventory at risk.'),
            Forms\Components\Toggle::make('verify_with_counsel')->label('Verify with counsel'),
            Forms\Components\Textarea::make('source_note')->label('Source / citation / note')->rows(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('effective_from')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->wrap()->extraAttributes(['style' => 'min-width:16rem']), // UI audit #7: was 1-2 words per line
                Tables\Columns\TextColumn::make('jurisdiction')->state(fn (EligibilityRule $r) => $r->jurisdictionLabel()),
                Tables\Columns\TextColumn::make('subtype')->placeholder('Any')->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('action')->badge()->color(fn ($state) => $state === 'block' ? 'danger' : 'warning'),
                Tables\Columns\TextColumn::make('effective_from')->date()->sortable(),
                Tables\Columns\TextColumn::make('effective_to')->date()->placeholder('Open')->visibleFrom('lg'),
                Tables\Columns\IconColumn::make('enabled')->boolean(),
                Tables\Columns\IconColumn::make('verify_with_counsel')->label('Counsel')->visibleFrom('md')->boolean()->trueIcon('heroicon-o-exclamation-triangle')->trueColor('warning')->falseColor('gray'),
                Tables\Columns\TextColumn::make('version')->prefix('v')->visibleFrom('xl'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('enabled'),
                Tables\Filters\SelectFilter::make('level')->options(['federal' => 'Federal', 'state' => 'State / local']),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEligibilityRules::route('/'),
            'create' => Pages\CreateEligibilityRule::route('/create'),
            'edit' => Pages\EditEligibilityRule::route('/{record}/edit'),
        ];
    }
}
