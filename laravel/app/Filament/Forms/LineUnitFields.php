<?php

namespace App\Filament\Forms;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Services\Money;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;

/**
 * #25: order-line unit fields shared by sales orders and purchase orders.
 * "Unit" offers the product's stock unit (blank) and its larger units (case, carton...). With a unit picked, the
 * user types how many of that unit; the stock-unit `qty` is filled in as unit_qty x factor (the model recomputes
 * it on save). Prices and costs stay per stock unit; the helper shows the per-unit equivalent.
 */
class LineUnitFields
{
    /** @return array<int, Forms\Components\Component> */
    public static function make(?\Closure $afterQtyChange = null, array $span = ['default' => 1, 'lg' => 2]): array
    {
        return [
            Forms\Components\Select::make('product_unit_id')->label('Unit')
                ->placeholder(fn (Get $get) => Product::find($get('product_id'))?->base_unit_name ?: 'Each')
                ->options(fn (Get $get) => self::options($get('product_id')))
                ->visible(fn (Get $get) => self::options($get('product_id')) !== [])
                ->rule(fn (Get $get) => function ($attr, $value, $fail) use ($get) {
                    if ($value && ! ProductUnit::where('id', $value)->where('product_id', $get('product_id'))->exists()) {
                        $fail('That unit belongs to a different product.');
                    }
                })
                ->live()
                ->afterStateUpdated(function ($state, Get $get, Set $set) use ($afterQtyChange) {
                    if ($state) {
                        $set('unit_qty', self::trim($get('unit_qty') ?: 1));
                        self::syncQty($get, $set);
                    } else {
                        $set('unit_qty', null);
                    }
                    $afterQtyChange && $afterQtyChange($get, $set);
                })
                ->columnSpan($span)->extraAttributes(['data-test' => 'line-unit']),
            Forms\Components\TextInput::make('unit_qty')
                ->label(fn (Get $get) => 'Qty ('.(ProductUnit::find($get('product_unit_id'))?->code ?? 'unit').')')
                ->numeric()->minValue(0.0001)
                ->required(fn (Get $get) => filled($get('product_unit_id')))
                ->visible(fn (Get $get) => filled($get('product_unit_id')))
                ->formatStateUsing(fn ($state) => $state === null ? null : self::trim($state))
                ->helperText(fn (Get $get) => self::equals($get))
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set) use ($afterQtyChange) {
                    self::syncQty($get, $set);
                    $afterQtyChange && $afterQtyChange($get, $set);
                })
                ->columnSpan($span)->extraAttributes(['data-test' => 'line-unit-qty']),
        ];
    }

    /** Larger units of the product, e.g. [5 => 'Carton (CTN) = 10 Pack']. */
    public static function options(mixed $productId): array
    {
        $p = $productId ? Product::find($productId) : null;
        if (! $p) {
            return [];
        }

        return $p->units()->orderByDesc('factor')->get()
            ->mapWithKeys(fn (ProductUnit $u) => [$u->id => "{$u->name} ({$u->code}) = ".self::trim($u->factor).' '.$p->base_unit_name])->all();
    }

    /** Stock-unit qty for the chosen unit, or null when no unit is chosen. */
    public static function baseQty(mixed $unitId, mixed $unitQty): ?string
    {
        $u = $unitId ? ProductUnit::find($unitId) : null;
        if (! $u || ! is_numeric($unitQty)) {
            return null;
        }

        return Money::mul($unitQty, $u->factor);
    }

    /** "= 20 Pack" under the unit qty. */
    public static function equals(Get $get): ?string
    {
        $base = self::baseQty($get('product_unit_id'), $get('unit_qty'));

        return $base === null ? null : '= '.self::trim($base).' '.(Product::find($get('product_id'))?->base_unit_name ?: 'Each');
    }

    /** "$59.00 per CS" from a stock-unit price or cost. */
    public static function perUnitHint(Get $get, string $priceField): ?string
    {
        $u = ProductUnit::find($get('product_unit_id'));
        if (! $u || ! is_numeric($get($priceField))) {
            return null;
        }

        return '$'.number_format((float) Money::mul($get($priceField), $u->factor), 2).' per '.$u->code;
    }

    private static function syncQty(Get $get, Set $set): void
    {
        if (($base = self::baseQty($get('product_unit_id'), $get('unit_qty'))) !== null) {
            $set('qty', self::trim($base));
        }
    }

    public static function trim(mixed $v): string
    {
        $s = (string) $v;

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
