<?php

namespace App\Filament\Resources\VendorResource\RelationManagers;

use App\Models\Product;
use App\Models\ScheduledCost;
use App\Models\VendorItem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/** 0.17: the vendor's item numbers, last cost and pending scheduled cost changes. Editable by owners/admins. */
class VendorItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'vendorItems';

    protected static ?string $title = 'Vendor items';

    protected static ?string $icon = 'heroicon-o-list-bullet';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->canModule('vendors', 'view');
    }

    public function isReadOnly(): bool
    {
        return ! auth()->user()?->isAdmin();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('product_id')->label('Product')->required()->searchable()
                ->getSearchResultsUsing(fn (string $search) => Product::query()->notArchived()
                    ->where(fn ($q) => $q->where('sku', 'like', "%{$search}%")->orWhere('upc', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                    ->limit(50)->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} - {$p->name}"])->all())
                ->getOptionLabelUsing(fn ($value) => ($p = Product::find($value)) ? "{$p->sku} - {$p->name}" : null),
            Forms\Components\TextInput::make('vendor_item_no')->label('Vendor item #')->required()->maxLength(60)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('vendor_id', $this->getOwnerRecord()->id)),
            Forms\Components\TextInput::make('last_cost')->numeric()->minValue(0),
            Forms\Components\DatePicker::make('last_cost_on'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('product'))
            ->defaultSort('vendor_item_no')
            ->columns([
                Tables\Columns\TextColumn::make('product.name')->label('Product')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('product.sku')->label('SKU')->searchable(),
                Tables\Columns\TextColumn::make('product.upc')->label('UPC')->searchable(),
                Tables\Columns\TextColumn::make('vendor_item_no')->label('Vendor item #')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('last_cost')->money('USD')->sortable(),
                Tables\Columns\TextColumn::make('last_cost_on')->date()->toggleable(),
                Tables\Columns\TextColumn::make('product.cost')->label('Current cost')->money('USD'),
                Tables\Columns\TextColumn::make('scheduled')->label('Scheduled cost changes')
                    ->state(fn (VendorItem $r) => ScheduledCost::where('product_id', $r->product_id)->where('vendor_id', $r->vendor_id)->whereNull('applied_at')
                        ->orderBy('effective_on')->get()->map(fn ($s) => '$'.number_format((float) $s->cost, 2).' on '.$s->effective_on->format('M j, Y'))->implode('; ') ?: '-'),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->bulkActions([]);
    }
}
