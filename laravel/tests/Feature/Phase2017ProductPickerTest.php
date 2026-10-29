<?php

namespace Tests\Feature;

use App\Filament\Forms\Components\ProductPickerField;
use App\Livewire\ProductPicker;
use App\Models\Department;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** 0.20.17: the ONE standard product picker (Livewire component) and where it is used. */
class Phase2017ProductPickerTest extends TestCase
{
    use RefreshDatabase;

    private Product $p1;

    private Product $p2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));
        $dept = Department::create(['code' => 'BEV', 'name' => 'Beverages', 'active' => true]);
        $vendor = Vendor::factory()->create(['name' => 'Acme Distro', 'code' => 'ACME'.uniqid()]);
        $this->p1 = Product::factory()->create(['sku' => 'PK-1', 'name' => 'Picker Cola', 'upc' => '012345678905', 'price' => '9.9900', 'cost' => '5.0000', 'active' => true, 'department_id' => $dept->id, 'preferred_vendor_id' => $vendor->id]);
        $this->p2 = Product::factory()->create(['sku' => 'PK-2', 'name' => 'Picker Water', 'upc' => '098765432109', 'price' => '4.5000', 'cost' => '2.0000', 'active' => true, 'department_id' => $dept->id]);
        ProductBarcode::create(['product_id' => $this->p1->id, 'code' => 'PK-CASE', 'note' => 'case']);
        VendorItem::create(['vendor_id' => $vendor->id, 'product_id' => $this->p1->id, 'vendor_item_no' => 'VND-PK-1']);
    }

    public function test_search_scan_browse_and_multi_select(): void
    {
        $lw = Livewire::test(ProductPicker::class, ['multi' => true])
            ->set('search', 'Picker')
            ->assertSee('Picker Cola')->assertSee('Picker Water')
            ->set('search', 'VND-PK-1')->assertSee('Picker Cola')
            ->set('scan', 'PK-CASE')->call('applyScan')
            ->assertSet('value', [$this->p1->id])
            ->set('scan', $this->p2->upc)->call('applyScan')
            ->assertSet('value', [$this->p1->id, $this->p2->id])
            ->call('remove', $this->p1->id)
            ->assertSet('value', [$this->p2->id])
            ->assertDispatched('product-picker-changed');

        $lw->call('openBrowse')->assertSet('browsing', true)->assertSee('Picker Cola')
            ->set('browseDepartment', $this->p1->department_id)
            ->set('browseVendor', $this->p1->preferred_vendor_id)
            ->assertSee('wire:key="tile-'.$this->p1->id.'"', false)->assertDontSee('wire:key="tile-'.$this->p2->id.'"', false)
            ->call('toggle', $this->p1->id)
            ->assertSet('value', [$this->p2->id, $this->p1->id])
            ->call('loadMore');
        $this->assertGreaterThanOrEqual(60, $lw->get('browseLimit'));
    }

    public function test_single_mode_replaces_and_miss_is_reported(): void
    {
        Livewire::test(ProductPicker::class)
            ->set('scan', 'PK-1')->call('applyScan')->assertSet('value', $this->p1->id)
            ->set('scan', 'PK-2')->call('applyScan')->assertSet('value', $this->p2->id)
            ->set('scan', 'NO-SUCH-THING-XYZ')->call('applyScan')
            ->assertSet('value', $this->p2->id)->assertSee('Nothing matched');
    }

    public function test_open_mode_links_results_to_the_product_page(): void
    {
        $url = \App\Filament\Resources\ProductResource::getUrl('edit', ['record' => $this->p1]);
        Livewire::test(ProductPicker::class, ['mode' => 'open'])
            ->set('search', 'Picker Cola')->assertSee($url, false)
            ->call('add', $this->p1->id)->assertSet('value', null);
    }

    public function test_thumbnail_is_shown_when_the_product_has_an_image(): void
    {
        $this->p1->update(['image_path' => 'catalog/x.png']);
        Livewire::test(ProductPicker::class)->set('search', 'Picker Cola')->assertSee('catalog/x.png', false);
    }

    public function test_products_and_departments_lists_offer_catalog_search(): void
    {
        $this->get('/admin/products')->assertOk()->assertSee('Catalog search');
        $this->get('/admin/departments')->assertOk()->assertSee('Catalog search');
    }

    public function test_add_products_merges_lines_without_blanks_or_duplicates(): void
    {
        $make = fn (Product $p) => ['product_id' => $p->id, 'qty' => 1, 'unit_price' => (string) $p->price];
        $lines = ProductPickerField::mergeLines(['a' => ['product_id' => null, 'qty' => 1]], [$this->p1->id, $this->p2->id], $make);
        $this->assertCount(2, $lines);
        $this->assertEqualsCanonicalizing([$this->p1->id, $this->p2->id], array_column($lines, 'product_id'));

        $again = ProductPickerField::mergeLines($lines, [$this->p1->id], $make);
        $this->assertCount(2, $again); // already on the order: not duplicated
    }

    public function test_pages_render_the_add_products_button(): void
    {
        $this->get('/admin/sales-orders/create')->assertOk()->assertSee('Add products');
        $this->get('/admin/purchase-orders/create')->assertOk()->assertSee('Add products');
    }
}
