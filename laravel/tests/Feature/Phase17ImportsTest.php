<?php

namespace Tests\Feature;

use App\Filament\Pages\Imports;
use App\Filament\Resources\VendorResource\Pages\EditVendor;
use App\Filament\Resources\VendorResource\RelationManagers\VendorItemsRelationManager;
use App\Imports\ImportRegistry;
use App\Imports\ImportRunner;
use App\Imports\ScheduledCostService;
use App\Imports\SheetReader;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerBalanceAudit;
use App\Models\CustomerGroup;
use App\Models\ImportBatch;
use App\Models\ImportMapping;
use App\Models\Inventory;
use App\Models\Journal;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\ScheduledCost;
use App\Models\ShipTo;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use App\Models\Warehouse;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class Phase17ImportsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private ImportRunner $r;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->owner = User::factory()->create(['role' => 'owner', 'active' => true]);
        $this->actingAs($this->owner);
        $this->r = app(ImportRunner::class);
    }

    private function csv(array $rows, string $name = 'f.csv'): string
    {
        $p = tempnam(sys_get_temp_dir(), 'imp').'-'.$name;
        $h = fopen($p, 'w');
        foreach ($rows as $r) {
            fputcsv($h, $r);
        }
        fclose($h);

        return $p;
    }

    private function imp(string $type, array $rows, array $extra = []): ImportBatch
    {
        $b = $this->r->upload($this->csv($rows), $type, $this->owner, $extra['vendor'] ?? null, $extra['effective'] ?? null, 'f.csv');
        if (isset($extra['mapping'])) {
            $this->r->setMapping($b, $extra['mapping']);
        }
        $this->r->startValidation($b->fresh());

        return $b->fresh();
    }

    public function test_customers_dry_run_then_commit_update_or_create_and_logged(): void
    {
        $b = $this->imp('customers', [
            ['Account Number', 'Name', 'Terms', 'Email', 'Credit limit', 'Delivery days'],
            ['CTEST-KY', 'Renamed Corner Market', 'Net 30', '', '2500', 'mon,thu'],
            ['NEW1', 'New Shop', 'COD', 'bad-email', '', ''],
            ['NEW2', 'Other Shop', 'Net 15', 'a@b.test', '1,000', 'Tuesday?'],
            ['NEW3', 'Third', '', '', '', ''],
            ['NEW3', 'Third again', '', '', '', ''],
        ]);
        $this->assertSame('validated', $b->status);
        $this->assertSame(['code' => 'Account Number', 'name' => 'Name', 'email' => 'Email', 'terms' => 'Terms', 'credit_limit' => 'Credit limit', 'delivery_days' => 'Delivery days'],
            array_intersect_key($b->mapping, array_flip(['code', 'name', 'email', 'terms', 'credit_limit', 'delivery_days'])));
        $this->assertSame(2, $b->valid_rows);
        $this->assertSame(3, $b->error_rows);
        $rows = $b->rows()->orderBy('row_no')->get();
        $this->assertSame('update', $rows[0]->action);
        $this->assertSame(['Net 15', 'Net 30'], $rows[0]->diff['terms']);
        $this->assertStringContainsString('Email', implode(' ', $rows[1]->errors));
        $this->assertStringContainsString('Delivery days', implode(' ', $rows[2]->errors));
        $this->assertStringContainsString('Duplicate of row 5', implode(' ', $rows[4]->errors));
        // dry run wrote nothing
        $this->assertSame('Net 15', Customer::where('code', 'CTEST-KY')->value('terms'));
        $this->assertNull(Customer::where('code', 'NEW3')->first());

        $this->r->startCommit($b, $this->owner);
        $b->refresh();
        $this->assertSame('done', $b->status);
        $this->assertSame([1, 1], [$b->created_count, $b->updated_count]);
        $this->assertSame('Net 30', Customer::where('code', 'CTEST-KY')->value('terms'));
        $this->assertSame('mon,thu', Customer::where('code', 'CTEST-KY')->value('delivery_days'));
        $this->assertNotNull(Customer::where('code', 'NEW3')->first());
        $this->assertNull(Customer::where('code', 'NEW1')->first());
        $this->assertTrue(ActivityLog::where('event', 'import_committed')->where('subject_id', $b->id)->exists());
        $this->assertTrue(ActivityLog::where('subject_type', Customer::class)->where('event', 'created')->where('subject_label', 'NEW3')->exists());
        $csv = $this->r->errorReportCsv($b);
        $this->assertStringContainsString('bad-email', $csv);
        $this->assertStringStartsWith('Row,Status,Problem,"Account Number"', $csv);
    }

    public function test_products_with_upcs_uom_vendor_items_and_conflicts(): void
    {
        Vendor::create(['code' => 'V9', 'name' => 'Nine']);
        $b = $this->imp('products', [
            ['SKU', 'UPC (selling unit)', 'Name', 'Tax class', 'Cost', 'List price', 'Unit 1 code', 'Unit 1 base units', 'Unit 1 barcode', 'Extra UPCs for the base unit (separate with |)', 'Preferred vendor code', 'Vendor item #'],
            ['NEW-SKU', '012345678905', 'New Thing', 'vapor', '3', '5.5', 'CS', '12', '112345678905', '212345678905', 'V9', 'V9-77'],
            ['TST-GEN-001', '', '', '', '16', '', '', '', '', '', '', ''],
            ['BAD-1', '000000000011', 'Steals a UPC', 'nicotine', '-1', '', 'CT', '', '', '', '', ''],
        ]);
        $rows = $b->rows()->orderBy('row_no')->get();
        $this->assertSame(['valid', 'valid', 'error'], $rows->pluck('status')->all());
        $err = implode(' ', $rows[2]->errors);
        foreach (['already belongs to TST-CIG-001', 'Tax class', 'Cost', 'Unit 1'] as $s) {
            $this->assertStringContainsString($s, $err);
        }
        $this->r->startCommit($b, $this->owner);
        $p = Product::where('sku', 'NEW-SKU')->first();
        $this->assertSame('vapor', $p->tax_class->value);
        $this->assertTrue($p->pact_covered); // phase 13 default still applies
        $this->assertSame(12.0, (float) ProductUnit::where('product_id', $p->id)->where('code', 'CS')->value('factor'));
        $this->assertSame(ProductUnit::where('product_id', $p->id)->value('id'), ProductBarcode::where('code', '112345678905')->value('product_unit_id'));
        $this->assertTrue(ProductBarcode::where('code', '212345678905')->where('product_id', $p->id)->exists());
        $this->assertSame($p->id, VendorItem::where('vendor_item_no', 'V9-77')->value('product_id'));
        $this->assertSame('16.0000', (string) Product::where('sku', 'TST-GEN-001')->value('cost'));
        $this->assertSame('TEST Lighter Display 50ct', Product::where('sku', 'TST-GEN-001')->value('name')); // blanks never wipe data
    }

    public function test_ship_tos_vendors_price_lists_and_opening_balances(): void
    {
        $this->r->startCommit($this->imp('vendors', [['Vendor code', 'Name', 'Manufacturer (yes/no)'], ['VNEW', 'New Vendor', 'yes']]), $this->owner);
        $this->assertTrue((bool) Vendor::where('code', 'VNEW')->value('is_manufacturer'));

        $b = $this->imp('ship_tos', [['Account Number', 'Ship-to name', 'State (2 letters)', 'City', 'Route name', 'Route sequence'],
            ['CTEST-KY', 'Main store', 'KY', 'Lexington', 'East', '3'], ['CTEST-KY', 'Store 2', 'KY', 'Paris', 'East', '4'], ['NOPE', 'X', 'KY', '', '', '']]);
        $this->assertSame(1, $b->error_rows);
        $this->r->startCommit($b, $this->owner);
        $this->assertSame('East', ShipTo::where('name', 'Main store')->value('route_name'));
        $this->assertSame(2, Customer::where('code', 'CTEST-KY')->first()->shipTos()->count());

        CustomerGroup::create(['code' => 'GOLD', 'name' => 'Gold']);
        $b = $this->imp('price_lists', [['SKU or UPC', 'Account Number (contract price)', 'Price group code', 'Minimum qty', 'Price', 'Starts on'],
            ['TST-GEN-001', 'CTEST-KY', '', '1', '20', ''], ['000000000103', '', 'GOLD', '10', '19', '2026-11-01'], ['TST-GEN-001', 'CTEST-KY', 'GOLD', '', '1', '']]);
        $this->assertSame([2, 1], [$b->valid_rows, $b->error_rows]);
        $this->r->startCommit($b, $this->owner);
        $this->assertSame(2, PriceRule::count());
        $again = $this->imp('price_lists', [['SKU or UPC', 'Account Number (contract price)', 'Minimum qty', 'Price'], ['TST-GEN-001', 'CTEST-KY', '1', '18']]);
        $this->assertSame('update', $again->rows()->value('action'));
        $this->r->startCommit($again, $this->owner);
        $this->assertSame(2, PriceRule::count());
        $this->assertSame('18.0000', (string) PriceRule::whereNotNull('customer_id')->value('price'));

        $b = $this->imp('opening_balances', [['Account Number', 'Opening balance (negative = credit)', 'As-of date'], ['CTEST-KY', '1,250.50', '10/31/2026'], ['CASH', '-20', '']]);
        $this->r->startCommit($b, $this->owner);
        $this->assertSame('1250.50', (string) Customer::where('code', 'CTEST-KY')->value('beginning_balance'));
        $this->assertSame('-20.00', (string) Customer::where('code', 'CASH')->value('beginning_balance'));
        $this->assertSame(2, CustomerBalanceAudit::count());
    }

    public function test_opening_inventory_sets_qty_and_posts_once(): void
    {
        $main = Warehouse::where('code', 'MAIN')->first();
        $b = $this->imp('opening_inventory', [['SKU or UPC', 'Warehouse code', 'Quantity on hand (base units)', 'Unit cost (blank = product cost)'],
            ['TST-GEN-001', 'MAIN', '150', '10'], ['TST-PIP-001', 'MAIN', '100', ''], ['TST-GEN-001', 'NOPE', '1', ''], ['ZZZ', 'MAIN', '1', '']]);
        $this->assertSame([2, 2], [$b->valid_rows, $b->error_rows]); // PIP already 100 -> unchanged (valid)
        $this->assertSame('unchanged', $b->rows()->where('row_no', 3)->value('action'));
        $journals = Journal::count();
        $this->r->startCommit($b, $this->owner);
        $this->assertSame(150.0, (float) Inventory::where('warehouse_id', $main->id)->where('product_id', Product::where('sku', 'TST-GEN-001')->value('id'))->value('qty'));
        $this->assertSame($journals + 1, Journal::count());
        $j = Journal::latest('id')->with('lines')->first();
        $this->assertSame(500.0, (float) $j->lines->sum('debit')); // 50 units x $10
    }

    public function test_vendor_price_file_now_and_scheduled_with_unmatched_and_mapping_template(): void
    {
        $v = Vendor::where('code', 'VTEST1')->first();
        $pip = Product::where('sku', 'TST-PIP-001')->first(); // cost 9
        VendorItem::create(['vendor_id' => $v->id, 'product_id' => $pip->id, 'vendor_item_no' => 'ACME-PIP']);
        $file = [['Item', 'Barcode', 'Net cost', 'Desc'], ['ACME-PIP', '', '9.90', 'Pipe'], ['', '000000000103', '15', 'Lighter'], ['ZZ-1', '999', '4', 'Mystery'], ['ACME-PIP2', '000000000080', 'abc', '']];
        $map = ['vendor_item_no' => 'Item', 'upc' => 'Barcode', 'cost' => 'Net cost', 'description' => 'Desc'];
        $b = $this->imp('vendor_costs', $file, ['vendor' => $v->id, 'mapping' => $map]);
        $rows = $b->rows()->orderBy('row_no')->get();
        $this->assertSame(['valid', 'valid', 'unmatched', 'error'], $rows->pluck('status')->all());
        $this->assertEquals(['9.0000', '9.9000', 10], $rows[0]->diff['cost']);
        $this->assertSame('unchanged', $rows[1]->action);
        $this->r->saveMapping($b->fresh(), 'Acme sheet', $this->owner);
        $this->r->startCommit($b->fresh(), $this->owner);
        $this->assertSame('9.9000', (string) $pip->fresh()->cost);
        $this->assertSame('9.9000', (string) VendorItem::where('vendor_item_no', 'ACME-PIP')->value('last_cost'));
        $this->assertStringContainsString('No matching product', $this->r->errorReportCsv($b->fresh()));

        // second file: saved per-vendor mapping auto-applies; future effective date schedules
        $b2 = $this->r->upload($this->csv([['Item', 'Barcode', 'Net cost', 'Desc'], ['ACME-PIP', '', '11', '']]), 'vendor_costs', $this->owner, $v->id, now()->addDays(5)->toDateString(), 'g.csv');
        $this->assertSame($map, $b2->mapping);
        $this->r->startValidation($b2);
        $this->assertSame('schedule', $b2->rows()->value('action'));
        $this->r->startCommit($b2->fresh(), $this->owner);
        $this->assertSame('9.9000', (string) $pip->fresh()->cost);
        $this->assertSame(1, ScheduledCost::whereNull('applied_at')->count());
        $this->assertSame(0, app(ScheduledCostService::class)->applyDue());
        $this->travel(6)->days();
        $this->artisan('costs:apply')->assertSuccessful();
        $this->assertSame('11.0000', (string) $pip->fresh()->cost);
        $this->assertTrue(ActivityLog::where('subject_type', Product::class)->where('subject_id', $pip->id)->where('event', 'updated')->exists());
        $this->assertSame(1, ImportMapping::where('vendor_id', $v->id)->count());
    }

    public function test_xlsx_reading_templates_and_chunked_steps(): void
    {
        $p = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        $w = new Writer;
        $w->openToFile($p);
        $w->addRow(Row::fromValues(['Vendor code', 'Name']));
        for ($i = 1; $i <= 600; $i++) {
            $w->addRow(Row::fromValues(["VX{$i}", "Vendor {$i}"]));
        }
        $w->close();
        $this->assertSame(['VX1', 'Vendor 1'], iterator_to_array(SheetReader::rows($p), false)[1]);
        config(['queue.default' => 'database']); // no worker: page polling drives it
        $b = $this->r->upload($p, 'vendors', $this->owner, null, null, 'v.xlsx');
        $this->assertSame(600, $b->total_rows);
        $this->r->startValidation($b);
        $this->assertTrue($this->r->step($b->fresh()));
        $this->assertSame(ImportRunner::CHUNK, $b->rows()->where('status', 'valid')->count());
        $b = $this->r->runToEnd($b);
        $this->assertSame('validated', $b->status);
        $this->r->startCommit($b, $this->owner);
        $this->assertSame('done', $this->r->runToEnd($b)->status);
        $this->assertSame(600, Vendor::where('code', 'like', 'VX%')->count());
        foreach (array_keys(ImportRegistry::TYPES) as $t) {
            foreach (['csv', 'xlsx'] as $f) {
                $this->get(route('imports.template', [$t, $f]))->assertOk()->assertDownload("{$t}-template.{$f}");
            }
        }
        $first = iterator_to_array(SheetReader::rows(ImportRunner::template('products', 'xlsx')), false)[0];
        $this->assertSame('SKU', $first[0]);
    }

    public function test_page_flow_and_access(): void
    {
        $this->get('/admin/imports')->assertOk()->assertSee('New import')->assertSee('Powered by DistroSKU');
        $file = UploadedFile::fake()->createWithContent('c.csv', "Account Number,Name\nPG1,Page Shop\n");
        $lw = Livewire::test(Imports::class)->set('type', 'customers')->set('file', $file)->call('upload');
        $b = ImportBatch::latest('id')->first();
        $lw->assertSet('batch', $b->id)->call('dryRun')->assertSee('Commit 1 rows')->call('commit');
        $this->assertSame('done', $b->fresh()->status);
        $this->assertNotNull(Customer::where('code', 'PG1')->first());
        $this->get('/admin/imports?batch='.$b->id)->assertOk()->assertSee('Imported: 1 created');
        $this->get(route('imports.errors', $b))->assertOk();
        $this->flushSession();
        $sales = User::where('role', 'sales')->first();
        $this->actingAs($sales)->get('/admin/imports')->assertForbidden();
        $this->get(route('imports.template', ['customers', 'csv']))->assertForbidden();
        config(['erp.permissions.imports.manage' => ['sales']]); // configurable
        $this->assertTrue(ImportRunner::canUse($sales));
    }

    public function test_migration_reruns(): void
    {
        $m = include database_path('migrations/2026_11_06_000001_phase17_imports.php');
        $m->up();
        $m->up();
        $this->assertTrue(Schema::hasIndex('import_rows', 'ir_batch_row_uq'));
    }

    public function test_vendor_items_tab_lists_and_edits_and_price_file_never_changes_selling_price(): void
    {
        $v = Vendor::where('code', 'VTEST1')->first();
        $pip = Product::where('sku', 'TST-PIP-001')->first();
        $price = (string) $pip->price;
        $vi = VendorItem::create(['vendor_id' => $v->id, 'product_id' => $pip->id, 'vendor_item_no' => 'ACME-PIP', 'last_cost' => 9]);
        $products = Product::count();
        $b = $this->r->upload($this->csv([['Vendor item #', 'New cost'], ['ACME-PIP', '12'], ['NEW-ITEM', '3']]), 'vendor_costs', $this->owner, $v->id, now()->addDays(3)->toDateString(), 'p.csv');
        $this->r->startValidation($b);
        $this->r->startCommit($b->fresh(), $this->owner);
        $this->assertSame($price, (string) $pip->fresh()->price);
        $this->assertSame($products, Product::count()); // unmatched never creates products
        $this->assertNull(Product::where('sku', 'NEW-ITEM')->first());
        $rm = VendorItemsRelationManager::class;
        $page = EditVendor::class;
        $this->get("/admin/vendors/{$v->id}/edit")->assertOk()->assertSee('Vendor items');
        Livewire::test($rm, ['ownerRecord' => $v, 'pageClass' => $page])->assertCanSeeTableRecords([$vi])
            ->assertSee('ACME-PIP')->assertSee('TST-PIP-001')->assertSee('$12.00 on')
            ->searchTable('ACME')->assertCanSeeTableRecords([$vi])
            ->callTableAction('edit', $vi, ['product_id' => $pip->id, 'vendor_item_no' => 'ACME-PIP-2', 'last_cost' => '10'])->assertHasNoTableActionErrors()
            ->callTableAction('create', null, ['product_id' => Product::where('sku', 'TST-GEN-001')->value('id'), 'vendor_item_no' => 'ACME-PIP-2'])->assertHasTableActionErrors(['vendor_item_no']);
        $this->assertSame('ACME-PIP-2', $vi->fresh()->vendor_item_no);
        $this->flushSession();
        $wh = User::where('role', 'warehouse')->first();
        $this->actingAs($wh);
        $this->assertTrue(Livewire::test($rm, ['ownerRecord' => $v, 'pageClass' => $page])->instance()->isReadOnly());
    }
}
