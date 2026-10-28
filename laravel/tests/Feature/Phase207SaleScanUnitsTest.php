<?php

namespace Tests\Feature;

use App\Livewire\CounterPos;
use App\Livewire\Driver\VanSale;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Pos\SaleScan;
use App\Services\InventoryService;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Issue #25: case / carton / pack barcodes work at the counter POS and in van sales (base units in the cart). */
class Phase207SaleScanUnitsTest extends TestCase
{
    use RefreshDatabase;

    private Product $gen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        // Fake units on a non-regulated product: base "Each", carton of 10, case of 50, each with its own barcode.
        $this->gen = Product::where('sku', 'TST-GEN-001')->firstOrFail(); // 25.0000 list
        $ctn = $this->gen->units()->create(['code' => 'CTN', 'name' => 'Carton', 'factor' => 10]);
        $cs = $this->gen->units()->create(['code' => 'CS', 'name' => 'Case', 'factor' => 50]);
        $this->gen->barcodes()->create(['code' => '20000000000107', 'product_unit_id' => $ctn->id]);
        $this->gen->barcodes()->create(['code' => '10000000000108', 'product_unit_id' => $cs->id]);
    }

    public function test_resolver_maps_each_barcode_level_to_base_units(): void
    {
        $this->assertSame('10', SaleScan::resolve('20000000000107')['qty']);
        $this->assertSame('CTN', SaleScan::resolve('20000000000107')['unit']->code);
        $this->assertSame('50', SaleScan::resolve('10000000000108')['qty']);
        $this->assertSame('1', SaleScan::resolve('000000000103')['qty']);       // the base UPC
        $this->assertSame('1', SaleScan::resolve('0000000000103')['qty']);      // same UPC in 13-digit EAN form
        $this->assertSame($this->gen->id, SaleScan::resolve('tst-gen-001')['product']->id); // SKU, any case
        $this->assertSame($this->gen->id, SaleScan::resolve('Lighter Display')['product']->id); // name fallback
        $this->assertNull(SaleScan::resolve('no-such-thing'));
    }

    public function test_archived_or_inactive_products_are_not_sold_by_barcode(): void
    {
        $this->gen->update(['active' => false]);
        $this->assertNull(SaleScan::resolve('20000000000107'));
    }

    public function test_pos_carton_and_case_scans_add_base_units_and_say_what_was_added(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'sales']));

        $lw = Livewire::test(CounterPos::class)
            ->set('customerId', \App\Models\Customer::where('code', 'CASH')->value('id'))
            ->set('scan', '20000000000107')->call('add')
            ->assertSet('cart.0.qty', '10')
            ->assertSet('message', 'Added 1 CTN (10 Each) × TEST Lighter Display 50ct.')
            ->set('scan', '10000000000108')->call('add')
            ->assertSet('cart.0.qty', '60')
            ->set('scan', '000000000103')->call('add')
            ->assertSet('cart.0.qty', '61')
            ->assertSet('message', 'Added 1 × TEST Lighter Display 50ct.');

        $lw->call('checkout')->assertSet('cart', []);
        $so = SalesOrder::where('channel', 'pos')->latest('id')->firstOrFail();
        $this->assertSame('61.0000', $so->lines()->first()->qty);
    }

    public function test_pos_unknown_code_still_reports_no_product(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'sales']));
        Livewire::test(CounterPos::class)->set('scan', '99999999')->call('add')
            ->assertSet('cart', [])->assertSet('error', 'No product for "99999999".');
    }

    public function test_van_sale_carton_scan_adds_base_units(): void
    {
        $driver = User::where('role', 'driver')->firstOrFail();
        $van = Warehouse::create(['code' => 'VAN1', 'name' => 'Van 1', 'state' => 'KY', 'is_van' => true, 'van_driver_id' => $driver->id]);
        app(InventoryService::class)->move($this->gen, $van, '100', 'adjustment', null, $this->gen->cost, 'load van');

        Livewire::actingAs($driver)->test(VanSale::class)
            ->set('scan', '20000000000107')->call('add')
            ->assertSet('cart.0.qty', '10')
            ->assertSet('ok', 'Added 1 CTN (10 Each) × TEST Lighter Display 50ct.')
            ->set('scan', '20000000000107')->call('add')
            ->assertSet('cart.0.qty', '20.0000');
    }
}
