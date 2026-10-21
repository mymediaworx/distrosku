<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\PactRegistrationResource\Pages;
use App\Models\PactRegistration;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** PACT Act registration record: ATF (US) and each state shipped into (15 U.S.C. 376(a)(1)). */
class PactRegistrationResource extends Resource
{
    use RoleGated;

    protected static string $module = 'pact';

    protected static ?string $model = PactRegistration::class;

    protected static ?string $navigationGroup = 'Tax Reports';

    protected static ?string $navigationLabel = 'PACT registrations';

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('jurisdiction')->required()->maxLength(2)->helperText('US = ATF (Form 5070.1); otherwise the 2-letter state.')
                ->dehydrateStateUsing(fn ($s) => strtoupper(trim((string) $s)))->regex('/^[A-Za-z]{2}$/'),
            Forms\Components\Toggle::make('active')->default(true),
            Forms\Components\TextInput::make('legal_name')->required(),
            Forms\Components\TextInput::make('trade_name'),
            Forms\Components\TextInput::make('principal_address')->columnSpanFull(),
            Forms\Components\TextInput::make('phone'),
            Forms\Components\TextInput::make('email')->email(),
            Forms\Components\TextInput::make('websites')->columnSpanFull(),
            Forms\Components\Textarea::make('other_places')->label('Other places of business (address + phone, one per line)')->rows(2)->columnSpanFull(),
            Forms\Components\Section::make('State agent and administrator')->columns(3)->schema([
                Forms\Components\TextInput::make('agent_name')->label('Agent for service in the state'),
                Forms\Components\TextInput::make('agent_address'),
                Forms\Components\TextInput::make('agent_phone'),
                Forms\Components\TextInput::make('administrator')->label('Tobacco tax administrator'),
                Forms\Components\TextInput::make('administrator_contact')->label('Where reports go (address / email / portal)')->columnSpan(2),
            ]),
            Forms\Components\DatePicker::make('filed_on')->helperText('Out-of-state PACT sales are allowed only with US + state records filed (Settings toggle).'),
            Forms\Components\DatePicker::make('expires_on'),
            Forms\Components\TextInput::make('reference')->label('Registration / acknowledgement no.'),
            Forms\Components\Textarea::make('notes')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('jurisdiction')->columns([
            Tables\Columns\TextColumn::make('jurisdiction')->badge(),
            Tables\Columns\TextColumn::make('legal_name')->searchable(),
            Tables\Columns\TextColumn::make('reference'),
            Tables\Columns\TextColumn::make('filed_on')->date(),
            Tables\Columns\TextColumn::make('expires_on')->date(),
            Tables\Columns\IconColumn::make('active')->boolean(),
        ])->actions([
            Tables\Actions\Action::make('print')->icon('heroicon-o-printer')->url(fn (PactRegistration $r) => route('pact.registration', $r), shouldOpenInNewTab: true),
            Tables\Actions\EditAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPactRegistrations::route('/'), 'create' => Pages\CreatePactRegistration::route('/create'),
            'edit' => Pages\EditPactRegistration::route('/{record}/edit')];
    }
}
