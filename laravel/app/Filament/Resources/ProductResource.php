<?php

namespace App\Filament\Resources;

use App\Catalog\ImageService;
use App\Enums\TaxClass;
use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Msa\Layout;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class ProductResource extends Resource
{
    use RoleGated { canDelete as roleGatedCanDelete; }

    protected static string $module = 'products';

    protected static ?string $model = Product::class;

    protected static ?string $navigationGroup = 'Products';

    protected static ?int $navigationSort = 1;

    /** 0.20.19: products turn up in the top-bar global search (name / SKU / brand / UPC / barcodes / vendor item #). */
    protected static ?string $recordTitleAttribute = 'name';

    protected static int $globalSearchResultsLimit = 15;

    public static function getGlobalSearchEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getGlobalSearchEloquentQuery()->where('products.active', true)->notArchived();
    }

    protected static function applyGlobalSearchAttributeConstraints(\Illuminate\Database\Eloquent\Builder $query, string $search): void
    {
        \App\Support\ProductSearch::matching($query, $search); // every word matches, any order, punctuation ignored
    }

    public static function modifyGlobalSearchQuery(\Illuminate\Database\Eloquent\Builder $query, string $search): void
    {
        \App\Support\ProductSearch::rank($query, $search);
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return trim($record->sku.' '.$record->name);
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Price' => '$'.number_format((float) $record->price, 2).' / '.($record->base_unit_name ?: 'Each')];
    }

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('department_id')->label('Department')
                    ->relationship('department', 'name', fn ($query) => $query->where('active', true))
                    ->searchable()->preload()->live(),
                Forms\Components\TextInput::make('sku')
                    ->label('SKU / barcode')
                    ->unique(ignoreRecord: true)->maxLength(50)
                    ->required(fn (Forms\Get $get, ?Product $record) => $record !== null || ! $get('department_id'))
                    ->helperText(fn (?Product $record) => $record ? 'Barcode value = SKU code.' : 'Leave blank to generate {DEPT}-0001 from the department.'),
                Forms\Components\TextInput::make('upc'),
                Forms\Components\TextInput::make('name')
                    ->required(),
                Forms\Components\Textarea::make('description')->rows(2)->columnSpanFull(),
                Forms\Components\FileUpload::make('image_path')->label('Image')->image()->disk('public')->directory('catalog')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])->maxSize(ImageService::MAX_KB)
                    ->helperText('Resized to max 400 px on save.'),
                Forms\Components\TextInput::make('brand'),
                Forms\Components\TextInput::make('manufacturer'),
                Forms\Components\Fieldset::make('KY EDI (XML returns)')->schema([
                    Forms\Components\TextInput::make('manufacturer_fein')->label('Manufacturer FEIN')->regex('/^\d{2}-?\d{7}$/')->maxLength(10)
                        ->dehydrateStateUsing(fn ($state) => $state ? preg_replace('/\D/', '', $state) : null),
                    Forms\Components\Select::make('msa_status')->label('MSA status')->options(array_combine($m = ['OPM', 'SPM', 'NPM', 'PM', 'NPM1', 'NPM2', 'N/A'], $m)),
                    Forms\Components\Select::make('ky_state_desc')->label('KY state desc (override)')->helperText('Blank = derived from tax class / weight.')
                        ->options(array_combine($c = ['KY-MS1', 'KY-DS1', 'KY-SS1', 'KY-LL1', 'KY-LL2', 'KY-LL3', 'KY-LL4', 'KY-PL1', 'KY-PL2', 'KY-PL3', 'KY-PL4',
                            'KY-TW1', 'KY-TW2', 'KY-TW3', 'KY-TW4', 'KY-TP1', 'KY-LCGR1', 'KY-VAP1', 'KY-VAP2'], $c))->searchable(),
                    Forms\Components\Select::make('edi_unit_desc')->label('EDI unit')->options(array_combine($u = ['CAN', 'TIN', 'PCH', 'PAK', 'BOX', 'BAG', 'TUB', 'STK', 'ECH', 'FOI', 'BUL', 'BUN', 'PLG', 'CUT', 'BOT', 'JAR', 'OTH'], $u)),
                ])->columns(4),
                Forms\Components\Fieldset::make('MSA (MULTICAT weekly file)')->schema([
                    Forms\Components\TextInput::make('msa_brand_family')->label('MSA directory brand family')->placeholder('Defaults to Brand')
                        ->helperText('Must match the state directory brand family (cigarettes / RYO).'),
                    Forms\Components\Select::make('msa_category_code')->label('MSA category')->options(Layout::CATEGORIES)->searchable(),
                    Forms\Components\TextInput::make('msa_items_per_unit')->label('Items per selling unit')->integer()->minValue(1)
                        ->helperText('Sticks / cans / packs in the MSA selling unit (carton = 200, 5-can roll = 5).'),
                    Forms\Components\TextInput::make('msa_units_per_sale')->label('MSA units per ERP unit')->numeric()->default(1)
                        ->helperText('1 if you sell the MSA unit; 0.1 if you sell packs and report cartons.'),
                    Forms\Components\TextInput::make('msa_upc')->label('Selling-unit UPC (if different)')->maxLength(14),
                    Forms\Components\TextInput::make('msa_description')->label('MSA description (blank = name)')->maxLength(50),
                    Forms\Components\Toggle::make('msa_promo')->label('Promotional item')->live(),
                    Forms\Components\TextInput::make('msa_promo_code')->label('Mfr promo code')->maxLength(10)->visible(fn (Forms\Get $get) => (bool) $get('msa_promo')),
                    Forms\Components\TextInput::make('msa_promo_description')->label('Promo description')->maxLength(50)->visible(fn (Forms\Get $get) => (bool) $get('msa_promo')),
                    Forms\Components\Select::make('msa_shipper_flag')->label('Shipper flag')->options(['S' => 'S - shipper', 'C' => 'C - component']),
                    Forms\Components\TextInput::make('msa_mfr_product_code')->label('Mfr item #')->maxLength(14),
                    Forms\Components\TextInput::make('msa_unit_size')->label('Consumer unit size')->numeric(),
                    Forms\Components\TextInput::make('msa_unit_size_desc')->label('Unit size desc')->maxLength(10),
                    Forms\Components\Toggle::make('msa_exclude')->label('Exclude from MSA'),
                ])->columns(4),
                Forms\Components\TextInput::make('category'),
                Forms\Components\Select::make('tax_class')->options(TaxClass::options())->required()->default('general')->live()
                    ->afterStateUpdated(fn ($state, Forms\Set $set) => in_array($state, Product::PACT_DEFAULT_CLASSES, true) ? $set('pact_covered', true) : null),
                Forms\Components\Toggle::make('pact_covered')->label('PACT covered')
                    ->helperText('Defaults on for cigarette, RYO, smokeless and vapor; you can turn it off.')
                    ->required(),
                Forms\Components\Select::make('hemp_type')->options(['cbd' => 'CBD', 'delta8' => 'Delta-8', 'delta9' => 'Delta-9 (hemp-derived)', 'thca' => 'THCA', 'hhc' => 'HHC', 'other' => 'Other']),
                Forms\Components\TextInput::make('thc_mg')
                    ->numeric(),
                Forms\Components\Section::make('Eligibility (hemp / CBD / kratom / vape)')->collapsible()->columns(3)
                    ->description('Used by Compliance > Eligibility rules (sales order confirm, POS, van, portal) and Inventory at risk.')->schema([
                    Forms\Components\Select::make('product_subtype')->label('Subtype')->options(\App\Models\Product::SUBTYPES)->placeholder('None'),
                    Forms\Components\TextInput::make('thc_total_mg')->label('Total THC per container (mg)')->numeric()->minValue(0),
                    Forms\Components\TextInput::make('thc_serving_mg')->label('THC per serving (mg, optional)')->numeric()->minValue(0),
                    Forms\Components\Select::make('product_form')->label('Form')->options(\App\Models\Product::FORMS)->placeholder('Not set'),
                    Forms\Components\Toggle::make('hemp_cannabinoid')->label('Contains hemp-derived cannabinoid (THC/CBD)')
                        ->helperText('A vape with this (or Subtype Hemp/CBD, or any THC mg) is a THC vape: KY requires it on the CHFS registry.'),
                    Forms\Components\Toggle::make('synthetic_cannabinoid')->label('Synthetic / converted cannabinoid'),
                    Forms\Components\Toggle::make('flavored')->label('Flavored'),
                    Forms\Components\Select::make('fda_status')->label('FDA marketing authorization (vapor)')->options(\App\Models\Product::FDA_STATUSES)->placeholder('Not set'),
                ]),
                Forms\Components\Select::make('smokeless_type')->options(['snuff' => 'Snuff (incl. snus)', 'chew' => 'Chewing tobacco'])->helperText('Snuff/chew only: sets the KY tax basis'),
                Forms\Components\TextInput::make('net_weight_oz')->label('Net weight per consumer unit (oz)')->numeric(),
                Forms\Components\TextInput::make('units_per_case')->label('Consumer units per sold unit')->numeric()->default(1)->required(),
                Forms\Components\TextInput::make('sticks_per_unit')->label('Cigarette sticks per sold unit')->numeric()->helperText('Carton = 200'),
                Forms\Components\Toggle::make('premium_cigar'),
                Forms\Components\Toggle::make('ky_tax_paid_by_supplier')->label('KY tobacco tax already paid by supplier'),
                Forms\Components\TextInput::make('unit')
                    ->required(),
                Forms\Components\TextInput::make('reorder_point')->label('Reorder point')->numeric()->helperText('Low-stock alert when on-hand minus reserved is at or below this'),
                Forms\Components\TextInput::make('cost')
                    ->numeric()
                    ->default(0)
                    ->dehydrateStateUsing(fn ($state) => ($state === null || $state === '') ? 0 : $state)
                    ->prefix('$'),
                Forms\Components\TextInput::make('price')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->prefix('$'),
                Forms\Components\Toggle::make('active')
                    ->default(true),
                Forms\Components\Section::make('Customer portal sections')->collapsible()->columns(3)->schema([
                    Forms\Components\Checkbox::make('portal_promo')->label('Show in Promotions'),
                    Forms\Components\Checkbox::make('portal_new')->label('Show in New products')->live(),
                    Forms\Components\Checkbox::make('portal_featured')->label('Show in Featured'),
                    Forms\Components\DatePicker::make('portal_new_until')->label('New until (optional)')
                        ->helperText('Leave blank to keep it in New products until unticked.')->visible(fn (Forms\Get $get) => (bool) $get('portal_new')),
                    Forms\Components\TextInput::make('portal_sort')->label('Section sort order')->numeric()->integer()->default(0)
                        ->helperText('Lower shows first in portal sections.'),
                ]),
                Forms\Components\Section::make('Reordering')->collapsible()->columns(3)->schema([
                    Forms\Components\TextInput::make('reorder_qty')->label('Order multiple')->numeric()->minValue(0)->helperText('Suggested qty rounds up to this (e.g. a case).'),
                    Forms\Components\Select::make('preferred_vendor_id')->label('Preferred vendor')->relationship('preferredVendor', 'name')->searchable(),
                    Forms\Components\TextInput::make('lead_time_days')->label('Lead time (days)')->integer()->minValue(0)->placeholder('Vendor / default'),
                ]),
                Forms\Components\Section::make('Units, barcodes and lots')->collapsible()->columns(2)->schema([
                    Forms\Components\TextInput::make('base_unit_name')->label('Stock unit name')->default('Each')->maxLength(20)
                        ->helperText('Stock, prices and orders are in this unit (e.g. Pack).'),
                    Forms\Components\Toggle::make('track_lots')->label('Track lots + expiry (FEFO)')
                        ->helperText('Receiving asks for lot/expiry; picking enforces first-expiring-first-out.'),
                    Forms\Components\Repeater::make('units')->relationship()->label('Units (case > carton > pack)')->columns(3)->defaultItems(0)
                        ->schema([
                            Forms\Components\TextInput::make('code')->required()->maxLength(10)->placeholder('CS'),
                            Forms\Components\TextInput::make('name')->required()->maxLength(40)->placeholder('Case'),
                            Forms\Components\TextInput::make('factor')->label('Stock units in one')->numeric()->required()->minValue(0.0001),
                        ])->columnSpanFull(),
                    Forms\Components\Repeater::make('barcodes')->relationship()->label('Extra barcodes')->columns(3)->defaultItems(0)
                        ->schema([
                            Forms\Components\TextInput::make('code')->required()->maxLength(64)
                                ->rule(fn (?ProductBarcode $record) => Rule::unique('product_barcodes', 'code')->ignore($record?->id)),
                            Forms\Components\Select::make('product_unit_id')->label('Unit')->placeholder('Stock unit')
                                ->options(fn ($livewire) => ($prod = method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null) instanceof Product ? $prod->units()->pluck('name', 'id') : []),
                            Forms\Components\TextInput::make('note')->maxLength(60),
                        ])->columnSpanFull()->helperText('Save new units first, then pick them here.'),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')->label('')->disk('public')->width(60)->height(60)->visibleFrom('sm') // UI audit #7: phones show SKU, name, price
                    ->extraImgAttributes(['style' => 'object-fit:contain;width:60px;height:60px;background:#f1f5f9;border-radius:4px']),
                Tables\Columns\TextColumn::make('department.name')->label('Dept')->sortable()->toggleable()->visibleFrom('lg'),
                // Phones: name over SKU in one column so the price stays on screen.
                Tables\Columns\TextColumn::make('phone_summary')->label('Product')
                    ->getStateUsing(fn (Product $r) => \Illuminate\Support\Str::limit((string) $r->name, 24))
                    ->description(fn (Product $r) => $r->sku)
                    ->hiddenFrom('sm'),
                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()->visibleFrom('sm'),
                Tables\Columns\TextColumn::make('upc')
                    ->searchable()->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()->wrap()->extraAttributes(['style' => 'min-width:14rem'])->visibleFrom('sm'),
                Tables\Columns\TextColumn::make('brand')
                    ->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('manufacturer')
                    ->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('category')
                    ->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('tax_class')->badge()->formatStateUsing(fn ($state) => $state->label())->visibleFrom('md'),
                Tables\Columns\IconColumn::make('pact_covered')
                    ->boolean()->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('hemp_type')
                    ->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('thc_mg')
                    ->numeric()
                    ->sortable()->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('unit')
                    ->searchable()->visibleFrom('lg'),
                Tables\Columns\TextColumn::make('cost')
                    ->money()
                    ->sortable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('price')
                    ->money()
                    ->sortable(),
                Tables\Columns\IconColumn::make('active')
                    ->boolean()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('archived_at')->label('Archived')->dateTime()->placeholder('—')->toggleable()->visibleFrom('xl'),
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
                Tables\Filters\TernaryFilter::make('archived')->label('Show archived')
                    ->placeholder('Hide archived')->trueLabel('Archived only')->falseLabel('All (incl. archived)')
                    ->queries(true: fn ($q) => $q->whereNotNull('archived_at'), false: fn ($q) => $q, blank: fn ($q) => $q->whereNull('archived_at')),
                Tables\Filters\SelectFilter::make('department_id')->label('Department')->relationship('department', 'name'),
                Tables\Filters\SelectFilter::make('product_subtype')->label('Subtype')->options(\App\Models\Product::SUBTYPES),
                Tables\Filters\Filter::make('coa_expiring')->label('COA expired / expiring soon')
                    ->query(fn ($query) => $query->whereIn('id', \App\Models\ProductCoa::expiringSoon()->select('product_id'))),
            ])
            ->headerActions([
                Tables\Actions\Action::make('csv')->label('Export CSV')->icon('heroicon-o-arrow-down-tray')->url(fn () => route('catalog.csv'))
                    ->visible(fn () => auth()->user()?->canModule('catalog', 'view')),
                Tables\Actions\Action::make('pdf')->label('Catalog PDF')->icon('heroicon-o-document-arrow-down')->url(fn () => route('catalog.pdf'))
                    ->visible(fn () => auth()->user()?->canModule('catalog', 'view')),
            ])
            ->actions([
                Tables\Actions\Action::make('label')->label('Label')->icon('heroicon-o-qr-code')
                    ->url(fn (Product $r) => route('catalog.labels', ['ids' => $r->id]), shouldOpenInNewTab: true)
                    ->visible(fn () => auth()->user()?->canModule('catalog', 'view')),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('archive')->label('Archive')->icon('heroicon-o-archive-box')->color('warning')->requiresConfirmation()
                    ->visible(fn (Product $r) => ! $r->isArchived() && static::canArchive())
                    ->action(fn (Product $r) => $r->archive(auth()->user())),
                Tables\Actions\Action::make('unarchive')->label('Unarchive')->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (Product $r) => $r->isArchived() && static::canArchive())
                    ->action(fn (Product $r) => $r->unarchive()),
                Tables\Actions\DeleteAction::make()->visible(fn (Product $r) => ! $r->hasHistory()),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('labels')->label('Print labels')->icon('heroicon-o-qr-code')
                    ->action(fn ($records, $livewire) => $livewire->redirect(route('catalog.labels', ['ids' => $records->pluck('id')->implode(',')]))),
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('archive')->label('Archive selected')->icon('heroicon-o-archive-box')->color('warning')
                        ->requiresConfirmation()->visible(fn () => static::canArchive())->deselectRecordsAfterCompletion()
                        ->action(fn ($records) => $records->each(fn (Product $p) => $p->isArchived() ?: $p->archive(auth()->user()))),
                    Tables\Actions\BulkAction::make('unarchive')->label('Unarchive selected')->icon('heroicon-o-arrow-uturn-left')
                        ->visible(fn () => static::canArchive())->deselectRecordsAfterCompletion()
                        ->action(fn ($records) => $records->each(fn (Product $p) => $p->isArchived() ? $p->unarchive() : null)),
                    // Products with history are skipped (archive them instead).
                    Tables\Actions\DeleteBulkAction::make()->action(function ($records, $action) {
                        $kept = 0;
                        foreach ($records as $p) {
                            $p->hasHistory() ? $kept++ : $p->delete();
                        }
                        $kept ? Notification::make()->warning()->title("{$kept} product(s) have history and were not deleted - archive them instead.")->send() : $action->success();
                    }),
                ]),
            ]);
    }

    public static function canArchive(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public static function canDelete(Model $record): bool
    {
        return static::roleGatedCanDelete($record) && ! $record->hasHistory();
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\ProductResource\RelationManagers\VendorItemsRelationManager::class,
            \App\Filament\Resources\ProductResource\RelationManagers\CoasRelationManager::class,
            \App\Filament\Resources\ProductResource\RelationManagers\VapeListingsRelationManager::class,
            \App\Filament\RelationManagers\HistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
