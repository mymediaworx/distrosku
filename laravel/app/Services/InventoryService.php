<?php

namespace App\Services;

use App\Exceptions\InventoryException;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function row(Product $product, Warehouse $warehouse): Inventory
    {
        $inv = Inventory::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->lockForUpdate()->first();

        return $inv ?? Inventory::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'qty' => 0, 'reserved' => 0]);
    }

    /** Signed quantity change with a ledger row. Negative stock is refused. */
    public function move(Product $product, Warehouse $warehouse, mixed $qty, string $type, ?Model $reference = null, mixed $unitCost = null, ?string $note = null): StockMovement
    {
        return DB::transaction(function () use ($product, $warehouse, $qty, $type, $reference, $unitCost, $note) {
            if ($type !== 'count' && ($c = \App\Inventory\CountService::freezingCount($product->id, $warehouse->id))) {
                throw new InventoryException("{$product->sku} is frozen in {$warehouse->code} by count {$c->number}; finish or cancel the count first.");
            }
            $inv = $this->row($product, $warehouse);
            $new = Money::of($inv->qty)->plus(Money::of($qty));
            if ($new->isNegative()) {
                throw new InventoryException("Not enough stock for {$product->sku} in {$warehouse->code}: on hand {$inv->qty}, change {$qty}.");
            }
            $inv->qty = (string) $new;
            $inv->save();

            return StockMovement::create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'qty' => Money::s($qty),
                'unit_cost' => Money::s($unitCost ?? $product->cost),
                'type' => $type,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'note' => $note,
                'user_id' => auth()->id(),
            ]);
        });
    }

    public function reserve(Product $product, Warehouse $warehouse, mixed $qty): void
    {
        DB::transaction(function () use ($product, $warehouse, $qty) {
            $inv = $this->row($product, $warehouse);
            $available = Money::of($inv->qty)->minus(Money::of($inv->reserved));
            if ($available->isLessThan(Money::of($qty))) {
                throw new InventoryException("Not enough available stock for {$product->sku} in {$warehouse->code}: available {$available}, need {$qty}.");
            }
            $inv->reserved = (string) Money::of($inv->reserved)->plus(Money::of($qty));
            $inv->save();
        });
    }

    public function release(Product $product, Warehouse $warehouse, mixed $qty): void
    {
        DB::transaction(function () use ($product, $warehouse, $qty) {
            $inv = $this->row($product, $warehouse);
            $r = Money::of($inv->reserved)->minus(Money::of($qty));
            $inv->reserved = (string) ($r->isNegative() ? Money::of(0) : $r);
            $inv->save();
        });
    }

    public function transfer(Product $product, Warehouse $from, Warehouse $to, mixed $qty, ?string $note = null): void
    {
        DB::transaction(function () use ($product, $from, $to, $qty, $note) {
            $this->move($product, $from, Money::of($qty)->negated(), 'transfer_out', null, null, $note);
            $this->move($product, $to, $qty, 'transfer_in', null, null, $note);
        });
    }
}
