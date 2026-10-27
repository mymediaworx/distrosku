<?php

namespace Tests\Feature;

use App\Filament\Pages\Modules;
use App\Filament\Resources\VendorBillResource;
use App\Models\User;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 0.20.5: Counter Pickup module tile after Inventory; wider vendor bill lines. */
class Phase205ModulesBillLinesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->actingAs(User::factory()->create(["role" => "owner", "active" => true]));
    }

    public function test_counter_pickup_tile_after_inventory(): void
    {
        $labels = array_column(Modules::tiles(), 'label');
        $i = array_search('Inventory', $labels, true);
        $this->assertSame('Counter Pickup', $labels[$i + 1] ?? null);
        $this->assertSame(0, Modules::section('Counter Pickup'));
        $this->assertCount(24, Modules::definitions()) // 0.20.17: Brand & Quick tools folded into Company Settings;
        $this->get('/admin/sales/counter-pickup')->assertOk();
        $this->assertTrue(version_compare(config('erp.version'), '0.20.5', '>='));
    }

    public function test_vendor_bill_create_renders_lines_table(): void
    {
        $html = $this->get(VendorBillResource::getUrl('create'))->assertOk()->getContent();
        foreach (['Lines', 'Product / item', 'GL account', 'Unit cost', 'Line total', 'Add line', 'Bill total', 'vb-lines-repeater'] as $s) {
            $this->assertStringContainsString($s, $html, $s);
        }
    }
}
