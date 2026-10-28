<?php

namespace Tests\Feature;

use App\Livewire\CounterPos;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** 0.20.12: POS Browse can reach every product in a department (page by page), not just the first 60. */
class Phase2012PosBrowseDepartmentTest extends TestCase
{
    use RefreshDatabase;

    private const N = 520;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->dept = Department::create(['code' => 'BIGD', 'name' => 'Big Department', 'active' => true]);
        $base = (array) DB::table('products')->where('sku', 'TST-GEN-001')->first();
        unset($base['id']);
        foreach (array_chunk(range(1, self::N), 260) as $chunk) {
            DB::table('products')->insert(array_map(fn ($i) => array_merge($base, ['sku' => sprintf('BIG-%04d', $i),
                'name' => sprintf('Big Item %04d', $i), 'upc' => null, 'department_id' => $this->dept->id, 'active' => true, 'archived_at' => null]), $chunk));
        }
        $this->actingAs(User::factory()->create(['role' => 'sales', 'active' => true]));
    }

    private function pos()
    {
        return Livewire::test(CounterPos::class)->set('customerId', Customer::where('code', 'CTEST-KY')->value('id'))->call('openBrowse');
    }

    public function test_department_browse_pages_through_all_products_with_total(): void
    {
        $lw = $this->pos();
        $this->assertArrayHasKey($this->dept->id, $lw->get('browseFacets')['departments']);
        $lw->call('browseDept', $this->dept->id);
        $this->assertSame(self::N, $lw->instance()->browseTotal);
        $this->assertCount(CounterPos::BROWSE_PAGE, $lw->instance()->browseTiles);
        $lw->assertSee('Showing 60 of 520 products')->assertSeeHtml('data-test="browse-more"');

        $taps = 0;
        while (count($lw->instance()->browseTiles) < self::N && $taps < 20) {
            $lw->call('loadMore');
            $taps++;
        }
        $skus = collect($lw->instance()->browseTiles)->pluck('sku');
        $this->assertSame(self::N, $skus->unique()->count());
        $this->assertSame('BIG-0520', $skus->last());
        $this->assertSame(8, $taps); // 60 per page
        $lw->assertSee('Showing 520 of 520 products')->assertDontSeeHtml('data-test="browse-more"');
        $lw->call('loadMore');
        $this->assertCount(self::N, $lw->instance()->browseTiles); // never past the total
    }

    public function test_filter_stays_inside_the_department_and_resets_paging(): void
    {
        $other = Product::where('sku', 'TST-GEN-001')->first();
        $lw = $this->pos()->call('browseDept', $this->dept->id)->call('loadMore');
        $lw->set('browseFilter', 'Item 01');
        $this->assertSame(CounterPos::BROWSE_PAGE, $lw->get('browseLimit'));
        // smart search (0.20.19): every word matches anywhere in the name, so "01" hits any item number containing 01
        $expected = collect(range(1, self::N))->filter(fn ($i) => str_contains(sprintf('%04d', $i), '01'))->count();
        $this->assertSame($expected, $lw->instance()->browseTotal);
        $this->assertTrue(collect($lw->instance()->browseTiles)->every(fn ($t) => str_starts_with($t['sku'], 'BIG-')));

        $lw->set('browseFilter', $other->name);
        $this->assertSame(0, $lw->instance()->browseTotal); // the product is not in this department
        $lw->call('browseDept', null);
        $this->assertGreaterThan(0, $lw->instance()->browseTotal);
    }
}
