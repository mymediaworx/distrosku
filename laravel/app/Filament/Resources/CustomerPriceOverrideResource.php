<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\CustomerPriceOverrideResource\Pages;
use App\Models\CustomerPriceOverride;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Support\ProductSearch;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** 0.19: customer special prices. Create/edit owner/admin; Sales view only (config permissions.customer_prices). */
class CustomerPriceOverrideResource extends Resource
{
    use RoleGated;

    protected static string $module = 'customer_prices';

    protected static ?string $model = CustomerPriceOverride::class;

    protected static ?string $pluralModelLabel = 'customer special prices'; // page title matches the sidebar (UI audit #6)

    protected static ?string $navigationGroup = 'Pricing';

    protected static ?string $navigationLabel = 'Customer special prices';

    protected static ?string $modelLabel = 'special price';

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?int $navigationSort = 2;

    public static function canEdit(Model $record): bool
    {
        return static::allowed('manage') && ! $record->used_at; // a used one-time price stays as history
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            // 0.20.13: full product lookup (search / scan / browse). Select kept for Filament search + edit; chips + scan UI above.
            Forms\Components\View::make('filament.forms.special-price-product-lookup')->columnSpanFull(),
            Forms\Components\Select::make('product_id')->label('Product (search)')
                ->required(fn ($livewire) => ! ($livewire->productLookupMulti ?? false))
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => ProductSearch::options($search))
                ->getOptionLabelUsing(fn ($value) => ($p = Product::find($value)) ? ProductSearch::label($p) : null)
                ->live()
                ->afterStateUpdated(function ($state, $livewire) {
                    if (! $state) {
                        return;
                    }
                    $id = (int) $state;
                    if ($livewire->productLookupMulti ?? false) {
                        if (! in_array($id, $livewire->pickedProductIds, true)) {
                            $livewire->pickedProductIds[] = $id;
                        }
                        $livewire->syncPickedToForm();
                    } else {
                        $livewire->pickedProductIds = [$id];
                    }
                })
                ->helperText('Type SKU, name, barcode/UPC, or vendor item #. Or use Scan / Browse catalog above.'),
            Forms\Components\Hidden::make('product_ids')
                ->default([])
                ->dehydrated(fn ($livewire) => (bool) ($livewire->productLookupMulti ?? false)),
            Forms\Components\Select::make('customer_id')->label('Customer')->searchable()->nullable()
                ->getSearchResultsUsing(fn (string $search) => \App\Models\Customer::optionsForSelect($search))
                ->getOptionLabelUsing(fn ($value) => ($c = \App\Models\Customer::find($value))?->labelWithAccount())
                ->helperText('Blank = all customers. A customer-specific price beats an all-customers one.'),
            Forms\Components\Placeholder::make('base_price_display')
                ->label('Current list price')
                ->content(function (Get $get, $livewire) {
                    $ids = $livewire->pickedProductIds ?? [];
                    if (count($ids) > 1) {
                        return count($ids).' products selected — each keeps its own list price; the special price below applies to all.';
                    }
                    $id = $ids[0] ?? $get('product_id');

                    return ProductSearch::basePriceLabel($id ? Product::find($id) : null);
                }),
            Forms\Components\TextInput::make('price')->numeric()->required()->prefix('$')->minValue(0)
                ->helperText(fn (Get $get, $livewire) => ($livewire->primaryBasePriceLabel ?? null) ?: null),
            Forms\Components\Select::make('duration')->options(CustomerPriceOverride::DURATIONS)->default('ongoing')->required()->live()
                ->helperText('One time: used by the first confirmed order line, then marked Used.'),
            Forms\Components\DatePicker::make('starts_on')->label('From')->required(fn (Get $get) => $get('duration') === 'range'),
            Forms\Components\DatePicker::make('ends_on')->label('To')->afterOrEqual('starts_on')->required(fn (Get $get) => $get('duration') === 'range')
                ->visible(fn (Get $get) => $get('duration') !== 'ongoing'),
            Forms\Components\Toggle::make('active')->default(true),
            Forms\Components\TextInput::make('note')->maxLength(255),
            Forms\Components\Placeholder::make('precedence')->columnSpanFull()->label('Order of prices')
                ->content('The LOWEST available price wins: customer special, all-customers special, contract, group, qty break / dated and list are all priced (promotions stack on contract / group / list, not on special prices). Ties: customer special > all-customers special > contract > group > qty break > dated > list. A one-time special is used only when it wins.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            Tables\Columns\TextColumn::make('product.sku')->label('SKU')->searchable(),
            Tables\Columns\TextColumn::make('product.name')->label('Product')->limit(40)->searchable(),
            Tables\Columns\TextColumn::make('customer.name')->label('Customer')->placeholder('All customers')->searchable(),
            Tables\Columns\TextColumn::make('price')->money('USD'),
            Tables\Columns\TextColumn::make('duration')->formatStateUsing(fn ($state) => CustomerPriceOverride::DURATIONS[$state] ?? $state),
            Tables\Columns\TextColumn::make('starts_on')->date()->placeholder('-'),
            Tables\Columns\TextColumn::make('ends_on')->date()->placeholder('-'),
            Tables\Columns\TextColumn::make('status')->badge()->state(fn (CustomerPriceOverride $r) => $r->status()),
            Tables\Columns\TextColumn::make('used_sales_order_id')->label('Used on order')->placeholder('-')
                ->formatStateUsing(fn ($state) => SalesOrder::find($state)?->number),
        ])->filters([
            Tables\Filters\SelectFilter::make('duration')->options(CustomerPriceOverride::DURATIONS),
            Tables\Filters\TernaryFilter::make('active'),
        ])->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomerPriceOverrides::route('/'),
            'create' => Pages\CreateCustomerPriceOverride::route('/create'),
            'edit' => Pages\EditCustomerPriceOverride::route('/{record}/edit'),
        ];
    }
}
