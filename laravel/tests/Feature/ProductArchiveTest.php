<?php

namespace Tests\Feature;

use App\Catalog\CatalogService;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Livewire\CounterPos;
use App\Livewire\QuickTools;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\ShipTo;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ProductArchiveTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $wh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
        $this->wh = Warehouse::where('code', 'MAIN')->first();
    }

    private function order(Product $p): SalesOrder
    {
        $c = Customer::factory()->create();
        $s = ShipTo::factory()->create(['customer_id' => $c->id, 'state' => 'KY']);
        $so = SalesOrder::create(['number' => 'SO-T'.uniqid(), 'customer_id' => $c->id, 'ship_to_id' => $s->id,
            'warehouse_id' => $this->wh->id, 'order_date' => now()->toDateString()]);
        $so->lines()->create(['product_id' => $p->id, 'qty' => '1', 'unit_price' => $p->price]);

        return $so;
    }

    public function test_archive_actions_and_delete_only_without_history(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner);
        $sold = Product::factory()->create(['sku' => 'HIST-1', 'name' => 'Sold Thing']);
        $fresh = Product::factory()->create(['sku' => 'NEW-1', 'name' => 'Never Used']);
        $so = $this->order($sold);

        $this->assertTrue($sold->hasHistory());
        $this->assertFalse($fresh->hasHistory());
        $this->assertFalse(ProductResource::canDelete($sold));
        $this->assertTrue(ProductResource::canDelete($fresh));

        Livewire::test(ListProducts::class)
            ->assertTableActionHidden('delete', $sold)->assertTableActionVisible('archive', $sold)
            ->assertTableActionVisible('delete', $fresh)
            ->callTableAction('archive', $sold);
        $sold->refresh();
        $this->assertNotNull($sold->archived_at);
        $this->assertSame($owner->id, $sold->archived_by);

        // hidden by default, visible with the "Show archived" filter
        Livewire::test(ListProducts::class)->assertCanNotSeeTableRecords([$sold])->assertCanSeeTableRecords([$fresh])
            ->filterTable('archived', true)->assertCanSeeTableRecords([$sold])->assertCanNotSeeTableRecords([$fresh])
            ->filterTable('archived', false)->assertCanSeeTableRecords([$sold, $fresh])
            ->assertTableActionVisible('unarchive', $sold)->callTableAction('unarchive', $sold);
        $this->assertNull($sold->fresh()->archived_at);

        // bulk archive / unarchive; bulk delete skips products with history
        Livewire::test(ListProducts::class)->callTableBulkAction('archive', [$sold, $fresh]);
        $this->assertSame(2, Product::whereNotNull('archived_at')->count());
        Livewire::test(ListProducts::class)->filterTable('archived', true)->callTableBulkAction('unarchive', [$sold, $fresh]);
        $this->assertSame(0, Product::whereNotNull('archived_at')->count());
        Livewire::test(ListProducts::class)->callTableBulkAction('delete', [$sold, $fresh]);
        $this->assertModelExists($sold);
        $this->assertModelMissing($fresh);

        Livewire::test(EditProduct::class, ['record' => $sold->id])->assertActionHidden('delete')->callAction('archive');
        $this->assertTrue($sold->fresh()->isArchived());

        // existing orders keep showing the archived product
        $this->assertSame('HIST-1', $so->fresh()->lines->first()->product->sku);
    }

    public function test_standard_users_cannot_archive(): void
    {
        $p = Product::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'sales']));
        $this->assertFalse(ProductResource::canArchive());
        Livewire::test(ListProducts::class)->assertTableActionHidden('archive', $p);
    }

    public function test_archived_products_hidden_from_pos_quick_lookup_exports_and_cannot_be_ordered(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $p = Product::factory()->create(['sku' => 'ARC-1', 'name' => 'Old Widget', 'upc' => '111222333444', 'active' => true]);
        $live = Product::factory()->create(['sku' => 'LIV-1', 'name' => 'Live Widget', 'active' => true]);
        $p->archive($owner);

        $this->assertCount(0, app(CatalogService::class)->lookup('ARC-1'));
        $this->assertCount(1, app(CatalogService::class)->lookup('LIV-1'));

        $this->actingAs($owner);
        $csv = $this->get(route('catalog.csv'))->streamedContent();
        $this->assertStringNotContainsString('ARC-1', $csv);
        $this->assertStringContainsString('LIV-1', $csv);
        $this->get(route('catalog.pdf'))->assertOk();

        Livewire::test(CounterPos::class)->set('scan', 'ARC-1')->call('add')->assertSet('cart', [])->assertSee('No product');

        Company::current()->rotateToken();
        $token = Company::current()->public_token;
        Livewire::test(QuickTools::class, ['token' => $token])->set('tab', 'lookup')->set('q', '111222333444')->call('search')
            ->assertSet('results', []);

        try {
            $this->order($p);
            $this->fail('archived product was ordered');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('archived', $e->getMessage());
        }
        $po = PurchaseOrder::create(['number' => 'PO-T1', 'vendor_id' => Vendor::factory()->create()->id, 'warehouse_id' => $this->wh->id, 'order_date' => now()]);
        $this->expectException(ValidationException::class);
        $po->lines()->create(['product_id' => $p->id, 'qty' => '1', 'unit_cost' => '1']);
    }

    public function test_admin_create_defaults_active_on_and_cost_optional(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $dept = Department::create(['code' => 'ELEC', 'name' => 'Electronics']);
        Livewire::test(CreateProduct::class)
            ->assertFormSet(['active' => true, 'cost' => 0])
            ->fillForm(['department_id' => $dept->id, 'name' => 'Cable', 'price' => '4.99', 'cost' => null, 'unit' => 'each', 'tax_class' => 'general'])
            ->call('create')->assertHasNoFormErrors();
        $p = Product::where('name', 'Cable')->first();
        $this->assertTrue($p->active);
        $this->assertSame('0.0000', (string) $p->cost);
        $this->assertCount(1, app(CatalogService::class)->lookup('Cable'));
        $this->assertTrue((new Product)->active);
    }

    public function test_public_htaccess_denies_logs_and_stray_php(): void
    {
        $h = file_get_contents(public_path('.htaccess'));
        $this->assertStringContainsString('php_errorlog', $h);
        $this->assertStringContainsString('Require all denied', $h);
        $this->assertStringContainsString('upgrade-[0-9a-f]{16,64}', $h);
        $this->assertStringContainsString('deploy\\.php$', $h);
        $this->assertStringContainsString('# END VEGA-ERP', $h);
        preg_match('/<FilesMatch "([^"]+)">/', $h, $m);
        $re = '/'.$m[1].'/';
        foreach (['php_errorlog', 'error_log', 'laravel.log', '.env', '.user.ini', 'composer.json', 'backup.zip'] as $f) {
            $this->assertMatchesRegularExpression($re, $f, $f);
        }
        foreach (['index.php', 'html5-qrcode.min.js', 'app.css', 'logo.png', 'favicon.ico'] as $f) {
            $this->assertDoesNotMatchRegularExpression($re, $f, $f);
        }
    }
}
