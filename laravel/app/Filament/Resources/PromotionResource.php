<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\PromotionResource\Pages;
use App\Models\Product;
use App\Models\Promotion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PromotionResource extends Resource
{
    use RoleGated;

    protected static string $module = 'pricing';

    protected static ?string $model = Promotion::class;

    protected static ?string $navigationGroup = 'Promotions';

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required(),
            Forms\Components\Select::make('kind')->options(['percent' => '% off', 'amount' => '$ off per unit'])->default('percent')->required(),
            Forms\Components\TextInput::make('value')->numeric()->required()->minValue(0),
            Forms\Components\DatePicker::make('starts_on')->required(),
            Forms\Components\DatePicker::make('ends_on')->required()->afterOrEqual('starts_on'),
            Forms\Components\Toggle::make('active')->default(true),
            Forms\Components\Fieldset::make('Applies to (blank = any)')->schema([
                Forms\Components\Select::make('product_id')->label('Product')->searchable()->nullable()
                    ->getSearchResultsUsing(fn (string $search) => Product::notArchived()->where(fn ($w) => $w->where('sku', 'like', "%$search%")->orWhere('name', 'like', "%$search%"))->limit(30)->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} {$p->name}"]))
                    ->getOptionLabelUsing(fn ($value) => ($p = Product::find($value)) ? "{$p->sku} {$p->name}" : null),
                Forms\Components\Select::make('department_id')->label('Department')->relationship('department', 'name')->preload()->nullable(),
                Forms\Components\Select::make('customer_id')->label('Customer')->searchable()->nullable()
                    ->getSearchResultsUsing(fn (string $search) => \App\Models\Customer::optionsForSelect($search))
                    ->getOptionLabelUsing(fn ($value) => ($c = \App\Models\Customer::find($value))?->labelWithAccount()),
                Forms\Components\Select::make('customer_group_id')->label('Price group')->relationship('customerGroup', 'name')->preload()->nullable(),
            ])->columns(2),
        ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('starts_on', 'desc')->columns([
            Tables\Columns\TextColumn::make('name')->searchable(),
            Tables\Columns\TextColumn::make('value')->formatStateUsing(fn ($state, Promotion $r) => $r->kind === 'percent' ? rtrim(rtrim($state, '0'), '.').'%' : '$'.number_format((float) $state, 2))->label('Off'),
            Tables\Columns\TextColumn::make('product.sku')->label('Product')->placeholder('any'),
            Tables\Columns\TextColumn::make('department.name')->placeholder('any'),
            Tables\Columns\TextColumn::make('customer.name')->placeholder('any'),
            Tables\Columns\TextColumn::make('customerGroup.name')->label('Group')->placeholder('any'),
            Tables\Columns\TextColumn::make('starts_on')->date()->sortable(),
            Tables\Columns\TextColumn::make('ends_on')->date()->sortable(),
            Tables\Columns\IconColumn::make('active')->boolean(),
        ])->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [\App\Filament\RelationManagers\HistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromotions::route('/'),
            'create' => Pages\CreatePromotion::route('/create'),
            'edit' => Pages\EditPromotion::route('/{record}/edit'),
        ];
    }
}
