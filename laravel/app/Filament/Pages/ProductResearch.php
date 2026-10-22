<?php

namespace App\Filament\Pages;

use App\Catalog\CatalogService;
use App\Models\SharedProduct;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** Shared product research: price stats across all SKU entries linked by normalized name. */
class ProductResearch extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'filament.pages.table-page';

    protected static ?string $navigationGroup = 'Products';

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?string $title = 'Product research';

    protected static ?string $slug = 'research';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canModule('research', 'view');
    }

    public function table(Table $table): Table
    {
        return $table->query(CatalogService::researchQuery())->defaultSort('name')->columns([
            Tables\Columns\ImageColumn::make('image_path')->label('')->disk('public')->width(50)->height(50)
                ->extraImgAttributes(['style' => 'object-fit:contain;width:50px;height:50px;background:#f1f5f9;border-radius:4px']),
            Tables\Columns\TextColumn::make('name')->searchable()->sortable()->description(fn (SharedProduct $r) => Str::limit((string) $r->description, 80)),
            Tables\Columns\TextColumn::make('price_submissions_count')->label('Prices')->sortable(),
            Tables\Columns\TextColumn::make('avg_price')->label('Avg')->money('USD')->sortable(),
            Tables\Columns\TextColumn::make('min_price')->label('Min')->money('USD')->sortable(),
            Tables\Columns\TextColumn::make('max_price')->label('Max')->money('USD')->sortable(),
        ]);
    }
}
