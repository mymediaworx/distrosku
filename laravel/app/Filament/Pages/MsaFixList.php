<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ProductResource;
use App\Models\Product;
use App\Msa\Layout;
use App\Msa\MulticatBuilder;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** Tobacco / MSA products missing data needed for the MULTICAT BID record, with bulk fixes. Admin-level ('msa'). */
class MsaFixList extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'filament.pages.table-page';

    protected static ?string $navigationGroup = 'Reports';

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $title = 'MSA missing data';

    protected static ?string $slug = 'msa/fix-list';

    protected static ?int $navigationSort = 51;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) $u && $u->canModule('msa');
    }

    public function intro(): string
    {
        return 'Products that are tobacco (by tax class) or have an MSA category code, and are missing something the MSA file needs. '
            .'Bulk actions apply the suggested category (from tax class) and items per selling unit (from sticks per unit; cigarettes default 200). Edit a product for UPC, promo and selling-unit details.';
    }

    public function table(Table $table): Table
    {
        return $table->query(Product::query()->whereNull('archived_at')->where('msa_exclude', false)
            ->where(fn (Builder $q) => $q->whereIn('tax_class', MulticatBuilder::TOBACCO_CLASSES)->orWhereNotNull('msa_category_code'))
            ->where(fn (Builder $q) => $q->whereNull('msa_category_code')->orWhereNull('msa_items_per_unit')->orWhereNull('upc')->orWhere('upc', '')
                ->orWhere(fn (Builder $w) => $w->where('msa_promo', true)->whereNull('msa_promo_description'))))
            ->defaultSort('sku')->columns([
                Tables\Columns\TextColumn::make('sku')->label('SKU')->searchable()->sortable()
                    ->url(fn (Product $p) => ProductResource::getUrl('edit', ['record' => $p])),
                Tables\Columns\TextColumn::make('name')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('tax_class'),
                Tables\Columns\TextColumn::make('upc')->label('UPC'),
                Tables\Columns\TextColumn::make('msa_category_code')->label('MSA cat'),
                Tables\Columns\TextColumn::make('msa_items_per_unit')->label('Items/unit'),
                Tables\Columns\TextColumn::make('problems')->state(fn (Product $p) => implode('; ', MulticatBuilder::productProblems($p)))->wrap(),
                Tables\Columns\TextColumn::make('suggested')->state(fn (Product $p) => trim((MulticatBuilder::suggestCategory($p) ?? '?').' / '.(MulticatBuilder::suggestItems($p) ?? '?'))),
            ])
            ->actions([
                Tables\Actions\Action::make('set')->label('Set MSA')->form([
                    Forms\Components\Select::make('msa_category_code')->label('MSA category')->options(Layout::CATEGORIES)->required(),
                    Forms\Components\TextInput::make('msa_items_per_unit')->label('Items per selling unit')->integer()->minValue(1)->required(),
                ])->fillForm(fn (Product $p) => ['msa_category_code' => $p->msa_category_code ?: MulticatBuilder::suggestCategory($p),
                    'msa_items_per_unit' => $p->msa_items_per_unit ?: MulticatBuilder::suggestItems($p)])
                    ->action(fn (Product $p, array $data) => $p->update($data)),
                Tables\Actions\Action::make('exclude')->label('Not MSA')->color('gray')->requiresConfirmation()
                    ->action(fn (Product $p) => $p->update(['msa_exclude' => true])),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('suggest')->label('Apply suggested category + items')->requiresConfirmation()
                    ->action(function (Collection $rows) {
                        $n = 0;
                        foreach ($rows as $p) {
                            $u = array_filter(['msa_category_code' => $p->msa_category_code ?: MulticatBuilder::suggestCategory($p),
                                'msa_items_per_unit' => $p->msa_items_per_unit ?: MulticatBuilder::suggestItems($p)]);
                            if ($u) {
                                $p->update($u);
                                $n++;
                            }
                        }
                        Notification::make()->success()->title("$n product(s) updated")->send();
                    }),
            ]);
    }
}
