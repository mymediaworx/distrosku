<?php

namespace Tests\Feature;

use App\Filament\Resources\SalesOrderResource\Pages\CreateSalesOrder;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Regression: live 0.14.0 returned 500 on /admin/sales-orders/create when a line product was picked / on Create. */
class SalesOrderCreateRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_pick_product_without_customer_then_create(): void
    {
        $undo = Repeater::fake();
        $p = Product::where('sku', 'TST-GEN-001')->first();
        $c = Customer::where('code', 'CASH')->first() ?? Customer::first();
        $lw = Livewire::test(CreateSalesOrder::class)
            ->set('data.lines', [['product_id' => null, 'qty' => 1, 'unit_price' => null]])
            ->set('data.lines.0.product_id', $p->id)     // no customer yet
            ->assertHasNoErrors();
        $this->assertSame((float) $p->price, (float) $lw->get('data.lines.0.unit_price'));
        $lw->set('data.lines.0.qty', 3)
            ->set('data.customer_id', $c->id)->set('data.ship_to_id', $c->shipTos()->value('id'))
            ->call('create')->assertHasNoFormErrors();
        $undo();
        $line = SalesOrder::latest('id')->first()->lines()->first();
        $this->assertSame(['list', false], [$line->price_source, $line->price_overridden]);
    }

    /** Live 0.14.0 log: "[$q] was unresolvable" from getSearchResults('glass'). Filament injects $search by name. */
    public function test_product_select_search_on_order_line_and_other_pickers(): void
    {
        $undo = Repeater::fake();
        $lw = Livewire::test(CreateSalesOrder::class)->set('data.lines', [['product_id' => null, 'qty' => 1, 'unit_price' => null]]);
        $field = $lw->instance()->form->getFlatComponents(withHidden: true);
        $select = collect($field)->first(fn ($c) => $c instanceof \Filament\Forms\Components\Select && $c->getName() === 'product_id');
        $this->assertNotNull($select);
        $res = $select->getSearchResults('TST-GEN');
        $this->assertNotEmpty($res);
        $this->assertStringContainsString('TST-GEN-001', implode(' ', $res));
        $undo();
        // every getSearchResultsUsing closure must take $search (Filament resolves closure params by name)
        foreach (\Symfony\Component\Finder\Finder::create()->in(app_path())->name('*.php') as $f) {
            $this->assertDoesNotMatchRegularExpression('/getSearchResultsUsing\(\s*(static\s+)?fn\s*\(\s*(string\s+|\?string\s+)?\$(?!search\b)\w+/', $f->getContents(), $f->getRelativePathname());
            $this->assertDoesNotMatchRegularExpression('/getSearchResultsUsing\(\s*(static\s+)?function\s*\(\s*(string\s+|\?string\s+)?\$(?!search\b)\w+/', $f->getContents(), $f->getRelativePathname());
        }
    }

    public function test_database_and_queue_defaults_are_not_sqlite(): void
    {
        $this->assertStringContainsString("env('DB_CONNECTION', 'mysql')", file_get_contents(config_path('database.php')));
        $this->assertStringNotContainsString("'sqlite'),", file_get_contents(config_path('queue.php')));
    }
}
