<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\User;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 0.20.12: admin lists and forms must stay bounded at Jamison scale (909 customers, 8016 products).
 * vegatest1 500'd (PHP memory exhausted) after a user picked "All" per page: Filament remembers that choice
 * in the session, so every later visit rendered every row.
 */
class Phase2012LargeListsTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMERS = 1000;

    private const PRODUCTS = 8000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->clone('customers', self::CUSTOMERS, fn ($i) => ['code' => sprintf('BULK-C%05d', $i), 'name' => sprintf('Bulk Customer %05d', $i), 'email' => null]);
        $this->clone('products', self::PRODUCTS, fn ($i) => ['sku' => sprintf('BULK-P%05d', $i), 'name' => sprintf('Bulk Product %05d', $i)]);
        $this->actingAs(User::factory()->create(['role' => 'owner']));
    }

    private function clone(string $table, int $n, callable $vary): void
    {
        $base = (array) DB::table($table)->orderBy('id')->first();
        unset($base['id']);
        foreach (array_chunk(range(1, $n), 100) as $chunk) {
            DB::table($table)->insert(array_map(fn ($i) => array_merge($base, $vary($i)), $chunk));
        }
    }

    public function test_no_all_per_page_option_on_any_table(): void
    {
        foreach ([ListCustomers::class, ListProducts::class] as $page) {
            $opts = Livewire::test($page)->instance()->getTable()->getPaginationPageOptions();
            $this->assertNotContains('all', $opts, $page);
            $this->assertSame(100, max($opts));
        }
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $f) {
            if ($f->isFile() && $f->getExtension() === 'php' && preg_match("/paginat\w*\([^)]*'all'/", file_get_contents($f->getPathname()))) {
                $offenders[] = $f->getPathname();
            }
        }
        $this->assertSame([], $offenders);
    }

    /** A per-page choice of "all" left in the session (from before 0.20.12) must be ignored, not render 1000+ rows. */
    public function test_lists_ignore_a_remembered_all_and_stay_bounded(): void
    {
        foreach (['/admin/customers' => ListCustomers::class, '/admin/products' => ListProducts::class] as $url => $page) {
            $key = (new $page)->getTablePerPageSessionKey();
            $before = memory_get_usage();
            $html = $this->withSession([$key => 'all'])->get($url)->assertOk()->getContent();
            $rows = substr_count($html, 'fi-ta-row ');
            $this->assertGreaterThan(0, $rows, $url);
            $this->assertLessThanOrEqual(100, $rows, $url);
            $this->assertLessThan(64 * 1024 * 1024, memory_get_usage() - $before, $url);
            $this->assertLessThan(4 * 1024 * 1024, strlen($html), $url);
        }
    }

    public function test_forms_do_not_preload_big_option_lists(): void
    {
        $checked = 0;
        foreach (['/admin/sales-orders/create', '/admin/price-rules/create', '/admin/ship-tos/create', '/admin/inventories/create',
            '/admin/stock-movements/create', '/admin/buydowns/create', '/admin/promotions/create', '/admin/purchase-orders/create',
            '/admin/products/create', '/admin/customer-price-overrides/create', '/admin/vendor-bills/create', '/admin/customer-licenses/create', '/admin/customers/create', '/admin/delivery-manifests/create'] as $url) {
            $res = $this->get($url);
            if (in_array($res->status(), [403, 404], true)) {
                continue; // module off by default in this build
            }
            $checked++;
            $html = $res->assertOk()->getContent();
            $this->assertLessThan(20, substr_count($html, 'Bulk Customer '), $url);
            $this->assertLessThan(20, substr_count($html, 'Bulk Product '), $url);
        }
        $this->assertGreaterThanOrEqual(8, $checked);
    }

    public function test_no_preload_on_selects_over_large_tables(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Filament'))) as $f) {
            if (! $f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            foreach (file($f->getPathname()) as $n => $line) {
                if (preg_match("/relationship\('(customer|product|vendor|preferredVendor|shipTo|priceRule)'/", $line) && str_contains($line, '->preload()')) {
                    $offenders[] = basename($f->getPathname()).':'.($n + 1);
                }
            }
        }
        $this->assertSame([], $offenders);
    }
}
