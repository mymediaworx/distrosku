<?php

namespace Tests\Feature;

use App\Exceptions\InventoryException;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_moves_are_ledgered_and_negative_refused(): void
    {
        $p = Product::factory()->create();
        $w = Warehouse::factory()->create();
        $svc = app(InventoryService::class);

        $svc->move($p, $w, '5', 'adjustment');
        $svc->move($p, $w, '-2', 'adjustment');
        $this->assertSame('3.0000', Inventory::first()->qty);
        $this->assertSame(2, StockMovement::count());
        $this->assertSame(0, bccomp('3', (string) StockMovement::sum('qty'), 4));

        $this->expectException(InventoryException::class);
        $svc->move($p, $w, '-4', 'adjustment');
    }

    public function test_transfer_between_warehouses(): void
    {
        $p = Product::factory()->create();
        [$a, $b] = Warehouse::factory()->count(2)->create();
        $svc = app(InventoryService::class);
        $svc->move($p, $a, '10', 'adjustment');
        $svc->transfer($p, $a, $b, '4');

        $this->assertSame('6.0000', Inventory::where('warehouse_id', $a->id)->first()->qty);
        $this->assertSame('4.0000', Inventory::where('warehouse_id', $b->id)->first()->qty);
    }

    public function test_reserve_respects_available(): void
    {
        $p = Product::factory()->create();
        $w = Warehouse::factory()->create();
        $svc = app(InventoryService::class);
        $svc->move($p, $w, '5', 'adjustment');
        $svc->reserve($p, $w, '4');
        $this->expectException(InventoryException::class);
        $svc->reserve($p, $w, '2');
    }
}
