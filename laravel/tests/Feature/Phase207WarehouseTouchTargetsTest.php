<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse as WH;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Issue #3: warehouse scanner hub uses full-size buttons instead of small text links (markup only). */
class Phase207WarehouseTouchTargetsTest extends TestCase
{
    use RefreshDatabase;

    private User $picker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
        $this->picker = User::factory()->create(['role' => 'warehouse']);
    }

    private function po(): PurchaseOrder
    {
        return PurchaseOrder::create(['number' => 'PO-TT'.uniqid(), 'vendor_id' => Vendor::factory()->create(['name' => 'Fake Supply Co'])->id,
            'warehouse_id' => WH::where('code', 'MAIN')->value('id'), 'order_date' => now(), 'status' => 'ordered', 'expected_at' => now()->addDays(2)]);
    }

    public function test_hub_renders_each_po_as_a_card_with_a_button(): void
    {
        $po = $this->po();
        $html = $this->actingAs($this->picker)->get('/warehouse')->assertOk()->getContent();

        $this->assertStringContainsString('data-test="hub-po"', $html);
        $this->assertStringContainsString($po->number, $html);
        $this->assertStringContainsString('Fake Supply Co', $html);
        $this->assertMatchesRegularExpression('#<a class="btn" href="[^"]*/warehouse/receive/'.$po->id.'">Scan receive</a>#', $html);
    }

    public function test_put_away_is_a_link_styled_as_button_not_a_button_inside_a_link(): void
    {
        $html = $this->actingAs($this->picker)->get('/warehouse')->assertOk()->getContent();

        $this->assertStringContainsString('<a class="btn wide"', $html);
        $this->assertDoesNotMatchRegularExpression('#<a[^>]*>\s*<button#', $html);
    }

    public function test_empty_sections_keep_their_messages(): void
    {
        $this->actingAs($this->picker)->get('/warehouse')->assertOk()
            ->assertSee('No open POs.')->assertSee('Nothing to pick.')->assertSee('No open counts.')->assertSee('Nothing to pack.');
    }

    public function test_layout_has_a_heading_and_touch_sized_controls(): void
    {
        $html = $this->actingAs($this->picker)->get('/warehouse')->assertOk()->getContent();

        $this->assertStringContainsString('<h1 class="sr-only">', $html);
        $this->assertStringContainsString('input,select,button{min-height:48px}', $html);
        $this->assertStringContainsString('a.btn{display:inline-flex;align-items:center;justify-content:center;min-height:48px', $html);
    }
}
