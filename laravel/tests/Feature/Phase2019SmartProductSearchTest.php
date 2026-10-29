<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Livewire\ProductPicker;
use App\Models\Department;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use App\Models\Warehouse;
use App\Support\ProductSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** 0.20.19: smart product search: every word matches in any order, punctuation ignored, abbreviations, ranking. */
class Phase2019SmartProductSearchTest extends TestCase
{
    use RefreshDatabase;

    private Product $owlPlat;

    private Product $owlMango;

    private Product $bmSweet;

    private Product $bmWine;

    private Product $swisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));
        $cigars = Department::create(['code' => 'CIG', 'name' => 'Cigars', 'active' => true]);
        $this->owlPlat = Product::factory()->create(['sku' => 'J-6328', 'name' => 'White Owl Mini 3/1.29 Platinum', 'brand' => 'White Owl', 'upc' => '012345678905', 'price' => '1.2900', 'department_id' => $cigars->id, 'base_unit_name' => 'Pack']);
        $this->owlMango = Product::factory()->create(['sku' => 'J-6329', 'name' => 'White Owl Mini 3/1.29 Mango', 'brand' => 'White Owl', 'upc' => '012345678912', 'price' => '1.2900', 'department_id' => $cigars->id]);
        $this->bmSweet = Product::factory()->create(['sku' => 'J-7001', 'name' => 'Black & Mild Sweet Plastic Tip', 'brand' => 'Black & Mild', 'upc' => '022222222226', 'price' => '1.4900', 'department_id' => $cigars->id]);
        $this->bmWine = Product::factory()->create(['sku' => 'J-7002', 'name' => 'Black & Mild Wine Wood Tip', 'brand' => 'Black & Mild', 'price' => '1.4900']);
        $this->swisher = Product::factory()->create(['sku' => 'J-8001', 'name' => 'Swisher Sweets Cigarillos Grape', 'brand' => 'Swisher Sweets', 'price' => '1.1900']);
        Product::factory()->create(['sku' => 'J-9999', 'name' => 'Inactive White Owl Platinum', 'active' => false]);
        $arch = Product::factory()->create(['sku' => 'J-9998', 'name' => 'Archived White Owl Platinum']);
        $arch->archive();
    }

    private function ids(string $q): array
    {
        return ProductSearch::query($q)->pluck('products.id')->all();
    }

    public function test_every_word_must_match_in_any_order(): void
    {
        $this->assertSame([$this->owlPlat->id], $this->ids('white owl platinum'));
        $this->assertSame([$this->owlPlat->id], $this->ids('platinum owl white'));
        $this->assertSame([$this->owlPlat->id], $this->ids('WHITE   OWL   platinum'));
        $this->assertEqualsCanonicalizing([$this->owlPlat->id, $this->owlMango->id], $this->ids('owl mini'));
        $this->assertSame([], $this->ids('white owl zzzzz'));
        // inactive and archived products stay out of pickers
        $this->assertNotContains(Product::where('sku', 'J-9999')->value('id'), $this->ids('white owl platinum'));
    }

    public function test_punctuation_and_case_are_ignored(): void
    {
        $this->assertContains($this->owlPlat->id, $this->ids('white owl 3/1.29 platinum'));
        $this->assertContains($this->owlPlat->id, $this->ids('white owl 3129'));
        $this->assertContains($this->bmSweet->id, $this->ids('black & mild sweet'));
        $this->assertContains($this->bmSweet->id, $this->ids('BLACK MILD SWEET'));
        $this->assertContains($this->bmSweet->id, $this->ids('Black n Mild sweet'));
        $this->assertContains($this->bmSweet->id, $this->ids('black and mild sweet'));
        $this->assertSame([$this->owlPlat->id], $this->ids('j6328'));
        $this->assertSame([$this->owlPlat->id], $this->ids('j-6328'));
        $this->assertSame([$this->bmSweet->id, $this->bmWine->id], array_slice($this->ids('&'), 0, 2), 'a lone symbol falls back to a plain name match instead of erroring');
    }

    public function test_abbreviations_expand_from_config(): void
    {
        $this->assertEqualsCanonicalizing([$this->bmSweet->id], $this->ids('bm sweet'));
        $this->assertEqualsCanonicalizing([$this->bmSweet->id, $this->bmWine->id], $this->ids('b&m'));
        $this->assertEqualsCanonicalizing([$this->bmSweet->id, $this->bmWine->id], $this->ids('b & m tip'));
        $this->assertSame([$this->swisher->id], $this->ids('ss grape'));

        config(['product_search.synonyms' => ['plat' => 'platinum', 'wo' => 'white owl']]);
        $this->assertSame([$this->owlPlat->id], $this->ids('wo plat'));
        // an abbreviation that is really in the name still matches as a whole word
        $odd = Product::factory()->create(['sku' => 'X-1', 'name' => 'SS Special Lighter']);
        config(['product_search.synonyms' => ['ss' => 'swisher sweets']]);
        $this->assertContains($odd->id, $this->ids('ss lighter'));
        $this->assertNotContains($this->bmSweet->id, $this->ids('ss'), 'whole-word only: "ss" must not match inside other words');
    }

    public function test_sku_upc_barcode_vendor_item_and_department_match(): void
    {
        $this->assertSame($this->owlPlat->id, ProductSearch::query('J-6328')->first()->id);
        $this->assertSame($this->owlPlat->id, ProductSearch::query('012345678905')->first()->id);
        $this->assertSame($this->owlPlat->id, ProductSearch::query('12345678905')->first()->id, '11-digit partial and 13-digit forms of a UPC still find it');
        $this->assertSame($this->owlPlat->id, ProductSearch::query('0012345678905')->first()->id);
        ProductBarcode::create(['product_id' => $this->owlMango->id, 'code' => 'CASE-0042', 'note' => 'case']);
        $this->assertSame([$this->owlMango->id], $this->ids('case0042'));
        $this->assertSame([$this->owlMango->id], $this->ids('CASE-0042'));
        $vendor = Vendor::factory()->create(['name' => 'Acme Distro', 'code' => 'ACME'.uniqid()]);
        VendorItem::create(['vendor_id' => $vendor->id, 'product_id' => $this->swisher->id, 'vendor_item_no' => 'AC/77-SW']);
        $this->assertSame([$this->swisher->id], $this->ids('ac77sw'));
        $this->assertSame([$this->swisher->id], $this->ids('grape ac77sw'));
        // department name: "cigars" + words
        $this->assertEqualsCanonicalizing([$this->owlPlat->id, $this->owlMango->id], $this->ids('cigars owl'));
        $this->assertSame([$this->bmSweet->id], $this->ids('cigars black'));
    }

    public function test_ranking_exact_first_then_name_start_then_more_words_then_stock(): void
    {
        // exact SKU beats a name hit on the same text
        $nameHit = Product::factory()->create(['sku' => 'Z-1', 'name' => 'J-6328 lookalike tag']);
        $this->assertSame([$this->owlPlat->id, $nameHit->id], array_slice($this->ids('J-6328'), 0, 2));

        // name starts with the typed text beats "contains"
        $starts = Product::factory()->create(['sku' => 'R-1', 'name' => 'Zebra Tip Cigar']);
        $contains = Product::factory()->create(['sku' => 'R-2', 'name' => 'Aardvark Zebra Tip Cigar']);
        $this->assertSame([$starts->id, $contains->id], $this->ids('zebra tip'));

        // in-stock wins a tie
        $a = Product::factory()->create(['sku' => 'S-1', 'name' => 'Alpha Quokka']);
        $b = Product::factory()->create(['sku' => 'S-2', 'name' => 'Alpha Quokka']);
        $wh = Warehouse::factory()->create();
        Inventory::create(['product_id' => $b->id, 'warehouse_id' => $wh->id, 'qty' => 5, 'reserved' => 0]);
        $this->assertSame([$b->id, $a->id], $this->ids('quokka'));
    }

    public function test_picker_shows_matches_price_and_pack_unit_to_tell_duplicates_apart(): void
    {
        $dup = Product::factory()->create(['sku' => 'J-6400', 'name' => 'White Owl Mini 3/1.29 Platinum', 'price' => '38.7000', 'base_unit_name' => 'Box']);
        ProductUnit::create(['product_id' => $dup->id, 'code' => 'CS', 'name' => 'Case', 'factor' => '12']);
        Livewire::test(ProductPicker::class, ['multi' => true])
            ->set('search', 'white owl platinum')
            ->assertSee('J-6328')->assertSee('J-6400')
            ->assertSee('$1.29 / Pack')->assertSee('$38.70 / Box · CS ×12')
            ->assertDontSee('Mango')
            ->call('add', $this->owlPlat->id)->assertSet('value', [$this->owlPlat->id]);
        $this->assertSame('Pack', ProductSearch::unitLabel($this->owlPlat->fresh()->load('units')));
        $this->assertStringContainsString('/ Box · CS ×12)', ProductSearch::label($dup->fresh()->load('units')));
    }

    public function test_barcode_scan_still_resolves_exactly(): void
    {
        ProductBarcode::create(['product_id' => $this->owlMango->id, 'code' => 'CASE-0042', 'note' => 'case']);
        Livewire::test(ProductPicker::class, ['multi' => true])
            ->set('scan', '012345678905')->call('applyScan')->assertSet('value', [$this->owlPlat->id])
            ->set('scan', 'CASE-0042')->call('applyScan')->assertSet('value', [$this->owlPlat->id, $this->owlMango->id])
            ->set('scan', 'white owl platinum')->call('applyScan')->assertSet('value', [$this->owlPlat->id, $this->owlMango->id])
            ->set('scan', 'zzz no such thing')->call('applyScan')->assertSee('Nothing matched');
        // the sales-screen scan: barcode first, then smart name search
        $this->assertSame($this->owlPlat->id, \App\Pos\SaleScan::resolve('012345678905')['product']->id);
        $this->assertSame($this->bmSweet->id, \App\Pos\SaleScan::resolve('bm sweet')['product']->id);
    }

    public function test_browse_filter_and_select_options_use_the_same_search(): void
    {
        $this->assertTrue(ProductSearch::browseQuery(null, null, 'platinum white')->whereKey($this->owlPlat->id)->exists());
        $this->assertFalse(ProductSearch::browseQuery(null, null, 'platinum white')->whereKey($this->owlMango->id)->exists());
        $opts = ProductSearch::options('owl plat');
        $this->assertArrayHasKey($this->owlPlat->id, $opts);
        $this->assertArrayNotHasKey($this->owlMango->id, $opts);
    }

    public function test_products_table_search_and_global_search(): void
    {
        // The page's table-search hook uses the same tokenized matcher (no rendering, so no intl needed).
        $page = new ListProducts();
        $m = new \ReflectionMethod($page, 'applyGlobalSearchToTableQuery');
        $m->setAccessible(true);
        $page->tableSearch = 'platinum white owl';
        $ids = $m->invoke($page, Product::query())->pluck('products.id')->all();
        $this->assertContains($this->owlPlat->id, $ids);
        $this->assertNotContains($this->owlMango->id, $ids);
        $this->assertNotContains($this->bmSweet->id, $ids);

        $titles = ProductResource::getGlobalSearchResults('white owl platinum')->map(fn ($r) => $r->title)->all();
        $this->assertCount(1, $titles);
        $this->assertStringContainsString('J-6328', $titles[0]);
        $this->assertCount(2, ProductResource::getGlobalSearchResults('bm'));
    }

    public function test_products_table_renders_the_smart_search(): void
    {
        if (! extension_loaded('intl')) {
            $this->markTestSkipped('Filament table rendering needs the intl extension.');
        }
        Livewire::test(ListProducts::class)
            ->searchTable('platinum white owl')
            ->assertCanSeeTableRecords([$this->owlPlat])
            ->assertCanNotSeeTableRecords([$this->owlMango, $this->bmSweet]);
    }

    public function test_search_is_one_query_and_bounded_for_a_large_catalog(): void
    {
        $dept = Department::first();
        $rows = [];
        for ($i = 1; $i <= 2000; $i++) {
            $rows[] = ['sku' => 'BULK-'.$i, 'name' => "Bulk Item {$i} Filler", 'price' => 1, 'cost' => 1, 'active' => true, 'tax_class' => 'general', 'department_id' => $dept->id, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            Product::insert($chunk);
        }
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $found = ProductSearch::query('bulk filler 1999')->limit(20)->get();
        $this->assertCount(1, \DB::getQueryLog());
        $this->assertSame('BULK-1999', $found->first()->sku);
        $this->assertLessThanOrEqual(20, ProductSearch::query('bulk item')->limit(20)->get()->count());
    }
}
