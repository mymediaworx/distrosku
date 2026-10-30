<?php

namespace App\Models\Concerns;

use App\Models\ProductUnit;
use App\Services\Money;

/**
 * #25: an order line entered in a unit (case, carton...). `qty` stays the stock-unit quantity everywhere
 * (stock, pricing, excise, GL); when a unit is set, qty is always unit_qty x factor, recomputed on save so
 * the server, not the browser, decides it. A unit that belongs to another product is dropped.
 */
trait HasLineUnit
{
    public static function bootHasLineUnit(): void
    {
        static::saving(function ($line) {
            if (! $line->product_unit_id) {
                $line->unit_qty = null;

                return;
            }
            $unit = ProductUnit::find($line->product_unit_id);
            if (! $unit || (int) $unit->product_id !== (int) $line->product_id || $line->unit_qty === null || $line->unit_qty === '') {
                $line->product_unit_id = null;
                $line->unit_qty = null;

                return;
            }
            $line->qty = Money::mul($line->unit_qty, $unit->factor);
        });
    }

    public function unit()
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    /** "2 CS" when entered in a unit, else null. */
    public function unitLabel(): ?string
    {
        if (! $this->product_unit_id || ! $this->unit) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $this->unit_qty, 4, '.', ''), '0'), '.').' '.$this->unit->code;
    }
}
