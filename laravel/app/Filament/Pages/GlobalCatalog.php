<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\PlatformOnly;
use App\Models\Company;
use App\Models\Product;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** All SKU entries linked to the shared product master, with that shared product's price stats. */
class GlobalCatalog extends Page implements HasTable
{
    use InteractsWithTable, PlatformOnly;

    protected static string $view = 'filament.pages.table-page';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $title = 'Global catalog';

    protected static ?string $slug = 'platform/catalog';

    public function table(Table $table): Table
    {
        $stats = DB::table('price_submissions')->selectRaw('shared_product_id, AVG(price) avg_price, MIN(price) min_price, MAX(price) max_price, COUNT(*) n')->groupBy('shared_product_id');
        $company = Company::current()->name;

        return $table->query(Product::query()->whereNull('products.archived_at')->whereNotNull('products.shared_product_id')
            ->leftJoinSub($stats, 'ps', 'ps.shared_product_id', '=', 'products.shared_product_id')
            ->select('products.*', 'ps.avg_price', 'ps.min_price', 'ps.max_price', 'ps.n'))
            ->defaultSort('sku')->columns([
                Tables\Columns\TextColumn::make('company')->state($company),
                Tables\Columns\TextColumn::make('sku')->label('SKU')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('upc')->label('UPC')->searchable(),
                Tables\Columns\TextColumn::make('price')->money('USD')->sortable(),
                Tables\Columns\TextColumn::make('avg_price')->label('Avg')->money('USD'),
                Tables\Columns\TextColumn::make('min_price')->label('Min')->money('USD'),
                Tables\Columns\TextColumn::make('max_price')->label('Max')->money('USD'),
                Tables\Columns\TextColumn::make('n')->label('Prices'),
            ]);
    }
}
