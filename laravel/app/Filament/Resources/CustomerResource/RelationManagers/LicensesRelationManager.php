<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Models\CustomerLicense;
use App\Models\LicenseType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/** 0.20.6/0.20.13: customer licenses. Type from Settings › License types. KY Site ID optional alongside License #. */
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
            $withCustomer ? Forms\Components\Select::make('customer_id')->label('Customer')->searchable()->required()
                ->getSearchResultsUsing(fn (string $search) => \App\Models\Customer::optionsForSelect($search))
                ->getOptionLabelUsing(fn ($value) => ($c = \App\Models\Customer::find($value))?->labelWithAccount()) : null,
            Forms\Components\Select::make('type')->label('License type')
                ->options(fn (?Model $record) => LicenseType::options($record?->type))->required()->searchable()
                ->live()->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                    if ($state && ! $get('state') && ($st = LicenseType::where('code', $state)->value('state'))) {
                        $set('state', $st);
                    }
                })
                ->helperText('Manage the list in Settings › License types.'),
            Forms\Components\TextInput::make('site_id')->label('Site ID')
                ->maxLength(40)
                ->nullable()
                ->dehydrateStateUsing(fn ($state) => ($s = trim((string) $state)) === '' ? null : $s)
                ->helperText(fn (Forms\Get $get) => strtoupper((string) $get('state')) === 'KY'
                    ? new HtmlString('Kentucky ABC Site ID (digits). Look up: <a href="'.e(CustomerLicense::KY_ABC_LOOKUP_URL).'" target="_blank" rel="noopener" class="underline">KY ABC license details</a> (copy Site ID from this form into the page — the ABC site does not accept a prefilled URL).')
                    : 'Optional site / location ID from the issuing authority.')
                ->rules([
                    fn (Forms\Get $get) => function (string $attr, $value, $fail) use ($get) {
                        if (blank($value)) {
                            return;
                        }
                        if (strtoupper((string) $get('state')) === 'KY' && ! preg_match('/^[0-9]{1,10}$/', (string) $value)) {
                            $fail('KY Site ID must be 1–10 digits.');
                        }
                    },
                ]),
            Forms\Components\TextInput::make('number')->label('License #')
                ->nullable()
                ->dehydrateStateUsing(fn ($state) => ($s = trim((string) $state)) === '' ? null : $s)
                ->helperText('Optional when a Site ID is set. At least one of Site ID or License # is required.'),
            Forms\Components\TextInput::make('state')->required()->maxLength(2)->default('KY')->live()
                ->dehydrateStateUsing(fn ($state) => strtoupper(trim((string) $state))),
            Forms\Components\DatePicker::make('expires_at')->label('Expires'),
            $withCustomer
                ? Forms\Components\Select::make('ship_to_id')->label('Ship-to (blank = all)')->relationship('shipTo', 'name')->searchable()
                : Forms\Components\Select::make('ship_to_id')->label('Ship-to (blank = all)')
                    ->options(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->shipTos()->pluck('name', 'id'))->searchable(),
            Forms\Components\Textarea::make('research_note')->label('Research note')->rows(2)->columnSpanFull()
                ->helperText('Where you verified this license (KY ABC lookup, phone call, etc.).'),
            Forms\Components\FileUpload::make('documents')->label('License documents (PDF / images)')->multiple()
                ->disk('local')->directory('license-documents')->visibility('private')
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(10240)
                ->downloadable()->openable()->reorderable()->columnSpanFull(),
            Forms\Components\Placeholder::make('proof_hint')->hiddenLabel()
                ->content('Enter a Site ID and/or License #. For Kentucky, either counts as a valid license.'),
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
                Tables\Columns\TextColumn::make('site_id')->label('Site ID')->searchable()->placeholder('—')->copyable()
                    ->copyMessage('Site ID copied'),
                Tables\Columns\TextColumn::make('number')->label('License #')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('state')->visibleFrom('sm'),
                Tables\Columns\TextColumn::make('documents')->label('Docs')->state(fn ($record) => count($record->documents ?? []) ?: null)->placeholder('—')->badge()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('shipTo.name')->label('Ship-to')->placeholder('All')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('expires_at')->label('Expires')->date()->sortable()
                    ->color(fn ($record) => $record->expires_at && $record->expires_at->isPast() ? 'danger'
                        : ($record->expires_at && $record->expires_at->lte(now()->addDays(30)) ? 'warning' : null))
                    ->placeholder('No expiry'),
                Tables\Columns\TextColumn::make('ky_lookup')->label('KY ABC')->state(fn () => 'Open lookup')
                    ->url(CustomerLicense::KY_ABC_LOOKUP_URL, shouldOpenInNewTab: true)
                    ->visible(fn (?Model $record) => $record && strtoupper((string) $record->state) === 'KY'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Add license')
                    ->mutateFormDataUsing(function (array $data) {
                        if (blank($data['site_id'] ?? null) && blank($data['number'] ?? null)) {
                            throw ValidationException::withMessages([
                                'number' => 'Enter a Site ID and/or License #.',
                            ]);
                        }

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(function (array $data) {
                        if (blank($data['site_id'] ?? null) && blank($data['number'] ?? null)) {
                            throw ValidationException::withMessages([
                                'number' => 'Enter a Site ID and/or License #.',
                            ]);
                        }

                        return $data;
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('No licenses on file');
    }
}
