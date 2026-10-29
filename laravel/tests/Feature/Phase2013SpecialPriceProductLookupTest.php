<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomerPriceOverrideResource\Pages\CreateCustomerPriceOverride;
use App\Filament\Resources\CustomerPriceOverrideResource\Pages\EditCustomerPriceOverride;
use App\Models\Customer;
use App\Models\CustomerPriceOverride;
use App\Models\Department;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use App\Support\ProductSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** 0.20.13: special-price form product lookup (search / scan / browse / multi-add / base price). */
class Phase2013SpecialPriceProductLookupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $p1;

    private Product $p2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($this->admin);
        $dept = Department::create(['code' => 'BEV', 'name' => 'Beverages', 'active' => true]);
        $vendor = Vendor::factory()->create(['name' => 'Acme Distro', 'code' => 'ACME'.uniqid()]);
        $this->p1 = Product::factory()->create([
            'sku' => 'SKU-LOOKUP-1', 'name' => 'Lookup Cola 12oz', 'upc' => '012345678901',
            'price' => '9.9900', 'active' => true, 'department_id' => $dept->id, 'preferred_vendor_id' => $vendor->id,
        ]);
        $this->p2 = Product::factory()->create([
            'sku' => 'SKU-LOOKUP-2', 'name' => 'Lookup Water 1L', 'upc' => '098765432109',
            'price' => '4.5000', 'active' => true, 'department_id' => $dept->id,
        ]);
        ProductBarcode::create(['product_id' => $this->p1->id, 'code' => 'BC-COLA-CASE', 'note' => 'case']);
        VendorItem::create(['vendor_id' => $vendor->id, 'product_id' => $this->p1->id, 'vendor_item_no' => 'VND-COLA-99']);
    }

    public function test_product_search_matches_sku_name_upc_barcode_and_vendor_item(): void
    {
        $this->assertTrue(ProductSearch::query('SKU-LOOKUP-1')->whereKey($this->p1->id)->exists());
        $this->assertTrue(ProductSearch::query('Lookup Cola')->whereKey($this->p1->id)->exists());
        $this->assertTrue(ProductSearch::query('012345678901')->whereKey($this->p1->id)->exists());
        $this->assertTrue(ProductSearch::query('BC-COLA-CASE')->whereKey($this->p1->id)->exists());
        $this->assertTrue(ProductSearch::query('VND-COLA-99')->whereKey($this->p1->id)->exists());
        $opts = ProductSearch::options('Cola');
        $this->assertArrayHasKey($this->p1->id, $opts);
        $this->assertStringContainsString('list $', $opts[$this->p1->id]);
    }

    public function test_browse_filters_by_department_and_vendor(): void
    {
        $deptId = $this->p1->department_id;
        $vendorId = $this->p1->preferred_vendor_id;
        $this->assertTrue(ProductSearch::browseQuery($deptId, null, '')->whereKey($this->p1->id)->exists());
        $this->assertTrue(ProductSearch::browseQuery(null, $vendorId, '')->whereKey($this->p1->id)->exists());
        $this->assertTrue(ProductSearch::browseQuery(null, null, 'VND-COLA')->whereKey($this->p1->id)->exists());
        $this->assertFalse(ProductSearch::browseQuery(null, $vendorId, '')->whereKey($this->p2->id)->exists());
    }

    public function test_multi_create_same_special_price_for_several_products(): void
    {
        $c = Customer::factory()->create();
        Livewire::test(CreateCustomerPriceOverride::class)
            ->fillForm([
                'product_ids' => [$this->p1->id, $this->p2->id],
                'customer_id' => $c->id,
                'price' => '3.25',
                'duration' => 'ongoing',
                'active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, CustomerPriceOverride::count());
        $this->assertEqualsCanonicalizing(
            [$this->p1->id, $this->p2->id],
            CustomerPriceOverride::pluck('product_id')->all()
        );
        $this->assertTrue(CustomerPriceOverride::where('price', '3.2500')->where('customer_id', $c->id)->count() === 2
            || CustomerPriceOverride::where('customer_id', $c->id)->where('product_id', $this->p1->id)->exists());
        foreach (CustomerPriceOverride::all() as $o) {
            $this->assertEquals('3.2500', $o->price);
            $this->assertSame($c->id, $o->customer_id);
        }
    }

    public function test_create_page_renders_the_standard_picker(): void
    {
        $this->get('/admin/customer-price-overrides/create')
            ->assertOk()
            ->assertSee('data-test="product-picker"', false)
            ->assertSee('data-test="picker-scan"', false)
            ->assertSee('data-test="picker-browse"', false)
            ->assertSee('Current list price');
    }

    public function test_edit_single_product_uses_picker_and_saves(): void
    {
        $o = CustomerPriceOverride::create([
            'product_id' => $this->p1->id, 'price' => '2.00', 'duration' => 'ongoing', 'active' => true,
            'created_by' => $this->admin->id,
        ]);
        Livewire::test(EditCustomerPriceOverride::class, ['record' => $o->getKey()])
            ->assertFormSet(['product_id' => $this->p1->id])
            ->fillForm(['product_id' => $this->p2->id])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($this->p2->id, $o->fresh()->product_id);
    }
}
