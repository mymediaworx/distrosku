<?php

namespace App\Filament\Forms\Components;

use App\Models\Product;
use Closure;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Get;
use Filament\Forms\Set;

/**
 * 0.20.17: Filament field wrapper around the ONE standard product picker (App\Livewire\ProductPicker).
 * State is a product id (single) or a list of ids (->multiple()).
 *
 *   ProductPickerField::make('product_id')                 // one product
 *   ProductPickerField::make('product_ids')->multiple()    // several
 *   ProductPickerField::addLinesAction('lines', fn ...)    // "Add products" button that appends repeater lines
 */
class ProductPickerField extends Field
{
    protected string $view = 'filament.forms.product-picker-field';

    protected bool|Closure $isMultiple = false;

    protected bool|Closure $showCost = false;

    protected bool|Closure $restrictRegulated = false;

    /** 0.21: hide regulated products (walk-in / cash customer). Re-evaluated each render. */
    public function hideRegulatedWhen(bool|Closure $condition = true): static
    {
        $this->restrictRegulated = $condition;

        return $this;
    }

    public function getRestrictRegulated(): bool
    {
        return (bool) $this->evaluate($this->restrictRegulated);
    }

    public function multiple(bool|Closure $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    public function showCost(bool|Closure $condition = true): static
    {
        $this->showCost = $condition;

        return $this;
    }

    public function isMultiple(): bool
    {
        return (bool) $this->evaluate($this->isMultiple);
    }

    public function getShowCost(): bool
    {
        return (bool) $this->evaluate($this->showCost);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->dehydrateStateUsing(fn ($state) => $this->isMultiple()
            ? array_values(array_unique(array_map('intval', (array) $state)))
            : (filled($state) ? (int) (is_array($state) ? ($state[0] ?? 0) : $state) : null));
    }

    /**
     * Modal "Add products" action: pick several products with search / scan / browse, then append one repeater
     * line per product. $makeLine receives (Product $p, Get $get) and returns the line's field values.
     * Existing blank lines (no product yet) are replaced, so the first add does not leave an empty row.
     */
    public static function addLinesAction(string $repeaterPath, Closure $makeLine, string $name = 'addProducts', bool $showCost = false, ?Closure $walkIn = null): Action
    {
        return Action::make($name)
            ->label('Add products (search / scan / browse)')
            ->icon('heroicon-o-magnifying-glass-plus')
            ->color('gray')
            ->modalHeading('Add products')
            ->modalWidth('4xl')
            ->modalSubmitActionLabel('Add to lines')
            ->form(fn (Get $get) => [
                // 0.21: $walkIn(Get) => true hides regulated products (walk-in / cash customer).
                static::make('product_ids')->label('Products')->multiple()->showCost($showCost)->hiddenLabel()
                    ->hideRegulatedWhen($walkIn ? (bool) $walkIn($get) : false),
            ])
            ->action(function (array $data, Get $get, Set $set) use ($repeaterPath, $makeLine, $walkIn) {
                $ids = array_values(array_unique(array_map('intval', $data['product_ids'] ?? [])));
                if ($ids !== [] && $walkIn && $walkIn($get)) { // server-side: never add a regulated line for a walk-in customer
                    $bad = Product::whereIn('id', $ids)->regulated()->get();
                    if ($bad->isNotEmpty()) {
                        $ids = array_values(array_diff($ids, $bad->pluck('id')->all()));
                        \Filament\Notifications\Notification::make()->danger()->title('Not added: '.\App\Compliance\WalkInRule::message($bad->first()))->send();
                    }
                }
                if ($ids === []) {
                    return;
                }
                $set($repeaterPath, static::mergeLines((array) ($get($repeaterPath) ?? []), $ids, $makeLine, $get));
            });
    }

    /**
     * Append one line per picked product to the repeater state. Blank placeholder rows (no product yet) are
     * dropped, and a product already on the order is skipped (edit its qty instead of duplicating).
     *
     * @param  array<string|int, array<string, mixed>>  $lines
     * @param  list<int>  $ids
     * @return array<string|int, array<string, mixed>>
     */
    public static function mergeLines(array $lines, array $ids, Closure $makeLine, mixed $get = null): array
    {
        $lines = array_filter($lines, fn ($l) => filled($l['product_id'] ?? null));
        $existing = collect($lines)->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $products = Product::whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            if (in_array($id, $existing, true) || ! ($p = $products->get($id))) {
                continue;
            }
            $lines[(string) \Illuminate\Support\Str::uuid()] = $makeLine($p, $get);
        }

        return $lines;
    }
}
