<?php

namespace Tests\Feature;

use App\Livewire\Portal\Catalog;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Product;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/** Issue #37: customers scan barcodes in the portal (camera or typed); pack/carton/case barcodes add the right quantity. */
class Phase207PortalCameraScanTest extends TestCase
{
    use RefreshDatabase;

    private Product $gen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->gen = Product::where('sku', 'TST-GEN-001')->firstOrFail();
        $ctn = $this->gen->units()->create(['code' => 'CTN', 'name' => 'Carton', 'factor' => 10]);
        $this->gen->barcodes()->create(['code' => '20000000000107', 'product_unit_id' => $ctn->id]);
    }

    private function signIn(string $code = 'CTEST-KY'): void
    {
        $k = CustomerContact::create(['customer_id' => Customer::where('code', $code)->value('id'), 'name' => 'Pat Buyer', 'email' => strtolower($code).'-scan@example.test']);
        $k->setPasswordFromLink('Secret12345');
        Auth::guard('portal')->login($k->fresh());
    }

    public function test_scanning_pack_and_carton_barcodes_adds_the_right_quantities(): void
    {
        $this->signIn();

        Livewire::test(Catalog::class)
            ->call('scan', '000000000103')                      // pack UPC
            ->assertSet('cart.'.$this->gen->id, '1')
            ->assertDispatched('portal-scan', ok: true, text: 'Added 1 × TEST Lighter Display 50ct.', cart: 1)
            ->call('scan', '20000000000107')                    // carton barcode
            ->assertSet('cart.'.$this->gen->id, '11')
            ->assertSet('message', 'Added 1 CTN (10 Each) × TEST Lighter Display 50ct.')
            ->call('scan', '0000000000103')                     // same UPC read as EAN-13
            ->assertSet('cart.'.$this->gen->id, '12');
    }

    public function test_unknown_barcode_reports_without_changing_the_cart(): void
    {
        $this->signIn();

        Livewire::test(Catalog::class)->call('scan', '99999999999')
            ->assertSet('cart', [])
            ->assertSet('error', 'No product found for barcode 99999999999.')
            ->assertDispatched('portal-scan', ok: false, text: 'No product found for barcode 99999999999.');
    }

    public function test_same_availability_rules_as_the_catalog(): void
    {
        $this->signIn();
        $this->gen->update(['active' => false]);

        Livewire::test(Catalog::class)->call('scan', '000000000103')
            ->assertSet('cart', [])
            ->assertDispatched('portal-scan', ok: false);
    }

    public function test_compliance_blocked_products_are_not_added(): void
    {
        $this->signIn('CTEST-IN');                              // Indiana customer: PACT blocks cigarettes for delivery
        $cig = Product::where('sku', 'TST-CIG-001')->firstOrFail();

        $lw = Livewire::test(Catalog::class)->set('fulfillment', 'delivery')->call('scan', $cig->upc);
        $lw->assertDispatched('portal-scan', ok: false);
        $this->assertArrayNotHasKey($cig->id, $lw->get('cart'));
    }

    public function test_scanner_markup_and_bundled_reader(): void
    {
        $this->signIn();

        Livewire::test(Catalog::class)
            ->assertSeeHtml('data-test="scan-open"')
            ->assertSeeHtml('data-test="scanner"')
            ->assertSeeHtml('zxing-browser-0.2.1.min.js');
        $this->assertFileExists(public_path('js/vendor/zxing-browser-0.2.1.min.js'));
        $this->assertFileExists(public_path('js/vendor/zxing-browser-LICENSE.txt'));
    }
}
