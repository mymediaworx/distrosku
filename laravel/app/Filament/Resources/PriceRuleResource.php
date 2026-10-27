<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\PriceRuleResource\Pages;
use App\Models\PriceRule;
use App\Models\Product;
use App\Pricing\PricingEngine;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Contract (customer), group, quantity-break and dated prices. Blank customer + group = all customers. */
class PriceRuleResource extends Resource
{
    use RoleGated;

    protected static string $module = 'pricing';

    protected static ?string $model = PriceRule::class;

    protected static ?string $pluralModelLabel = 'price lists'; // page title matches the sidebar (UI audit #6)

    protected static ?string $navigationGroup = 'Pricing';

    protected static ?string $navigationLabel = 'Price lists';

    protected static ?string $modelLabel = 'price';

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('product_id')->label('Product')->required()->searchable()
                ->getSearchResultsUsing(fn (string $search) => Product::notArchived()->where(fn ($w) => $w->where('sku', 'like', "%$search%")->orWhere('name', 'like', "%$search%"))->limit(30)->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} {$p->name}"]))
                ->getOptionLabelUsing(fn ($value) => ($p = Product::find($value)) ? "{$p->sku} {$p->name} (list \${$p->price})" : null),
            Forms\Components\Select::make('customer_id')->label('Customer (contract price)')->searchable()->nullable()->live()
                ->getSearchResultsUsing(fn (string $search) => \App\Models\Customer::optionsForSelect($search))
                ->getOptionLabelUsing(fn ($value) => ($c = \App\Models\Customer::find($value))?->labelWithAccount())
                ->disabled(fn (Get $get) => filled($get('customer_group_id'))),
            Forms\Components\Select::make('customer_group_id')->label('Price group')->relationship('customerGroup', 'name')->preload()->nullable()->live()
                ->disabled(fn (Get $get) => filled($get('customer_id')))->helperText('Leave customer and group blank for all customers.'),
            Forms\Components\TextInput::make('min_qty')->label('Minimum qty (break)')->numeric()->default(1)->minValue(1)->required(),
            Forms\Components\TextInput::make('price')->numeric()->required()->prefix('$')->minValue(0),
            Forms\Components\DatePicker::make('starts_on')->label('Effective'),
            Forms\Components\DatePicker::make('ends_on')->label('Expires')->afterOrEqual('starts_on'),
            Forms\Components\Toggle::make('active')->default(true),
            Forms\Components\TextInput::make('note')->maxLength(255),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            Tables\Columns\TextColumn::make('product.sku')->label('SKU')->searchable(),
            Tables\Columns\TextColumn::make('product.name')->label('Product')->searchable()->wrap(),
            Tables\Columns\TextColumn::make('kind')->label('Type')->badge()->state(fn (PriceRule $r) => PricingEngine::sourceLabel($r->scopeLabel())),
            Tables\Columns\TextColumn::make('customer.name')->placeholder('-'),
            Tables\Columns\TextColumn::make('customerGroup.name')->label('Group')->placeholder('-'),
            Tables\Columns\TextColumn::make('min_qty')->numeric(0)->label('Min qty'),
            Tables\Columns\TextColumn::make('price')->money('USD'),
            Tables\Columns\TextColumn::make('product.price')->label('List')->money('USD'),
            Tables\Columns\TextColumn::make('starts_on')->date()->placeholder('-'),
            Tables\Columns\TextColumn::make('ends_on')->date()->placeholder('-'),
            Tables\Columns\IconColumn::make('active')->boolean(),
        ])->filters([
            Tables\Filters\SelectFilter::make('customer_id')->relationship('customer', 'name')->label('Customer'),
            Tables\Filters\SelectFilter::make('customer_group_id')->relationship('customerGroup', 'name')->label('Group'),
            Tables\Filters\TernaryFilter::make('active'),
        ])->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\DeleteBulkAction::make()->visible(fn () => auth()->user()->canModule('pricing', 'manage'))]);
    }

    public static function getRelations(): array
    {
        return [\App\Filament\RelationManagers\HistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPriceRules::route('/'),
            'create' => Pages\CreatePriceRule::route('/create'),
            'edit' => Pages\EditPriceRule::route('/{record}/edit'),
        ];
    }
}
