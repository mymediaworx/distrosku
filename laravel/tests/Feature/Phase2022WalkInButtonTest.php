<?php

namespace Tests\Feature;

use App\Compliance\WalkInRule;
use App\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use App\Filament\Resources\ProductResource;
use App\Livewire\CounterPos;
use App\Livewire\CustomerPicker;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

/** 0.20.22: the POS "Walk-in (CASH)" button did nothing on live (public $walkIn property and walkIn() method shared a name). */
class Phase2022WalkInButtonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
    }

    /** Admin-style CASH account: the demo CASH is renamed out of the way first (account numbers are unique). */
    private function adminCash(string $code = 'CASH', array $extra = []): Customer
    {
        Customer::where('code', 'CASH')->update(['code' => 'OLD-DEMO-1', 'walk_in' => false]);

        return Customer::create(array_merge(['code' => $code, 'name' => 'CASH WALK-IN CUSTOMER', 'type' => 'retailer', 'terms' => 'cod', 'credit_limit' => 0, 'active' => true], $extra));
    }

    public function test_no_livewire_component_has_a_public_property_and_a_public_method_with_the_same_name(): void
    {
        // $wire.foo resolves to the property when both exist, so wire:click="foo" silently does nothing in the browser.
        $bad = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Livewire'), \FilesystemIterator::SKIP_DOTS)) as $fi) {
            $file = $fi->getPathname();
            if (! str_ends_with($file, '.php')) {
                continue;
            }
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file, strlen(app_path()) + 1));
            if (! class_exists($class) || ! is_subclass_of($class, Component::class)) {
                continue;
            }
            $r = new \ReflectionClass($class);
            foreach ($r->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
                if ($p->getDeclaringClass()->getName() === $class && $r->hasMethod($p->getName()) && $r->getMethod($p->getName())->isPublic()) {
                    $bad[] = $class.'::'.$p->getName();
                }
            }
        }
        $this->assertSame([], $bad);
    }

    public function test_button_calls_a_method_that_is_not_also_a_property(): void
    {
        $html = Livewire::test(CustomerPicker::class, ['walkIn' => true])->html();
        $this->assertStringContainsString('wire:click="selectWalkIn"', $html);
        $this->assertStringNotContainsString('wire:click="walkIn"', $html);
        $this->assertFalse(method_exists(CustomerPicker::class, 'walkIn'));
    }

    public function test_walk_in_button_selects_the_admin_created_cash_customer_with_a_visible_label(): void
    {
        $c = $this->adminCash();
        Livewire::test(CustomerPicker::class, ['walkIn' => true])
            ->call('selectWalkIn')
            ->assertSet('value', $c->id)
            ->assertSeeHtml('data-test="customer-selected"')
            ->assertSee('CASH WALK-IN CUSTOMER')
            ->assertSeeHtml('data-test="customer-walkin-badge"')
            ->assertDispatched('customer-picker-changed');
    }

    public function test_lookup_ignores_case_spaces_and_punctuation_and_prefers_active_exact_cash(): void
    {
        foreach (['cash', ' CASH ', 'Cash', 'CASH-WALKIN', 'Walk-In', 'cash walk in'] as $i => $code) {
            Customer::query()->delete();
            $c = Customer::create(['code' => $code, 'name' => 'X'.$i, 'type' => 'retailer', 'terms' => 'cod', 'credit_limit' => 0, 'active' => true]);
            $this->assertSame($c->id, WalkInRule::cashCustomer()?->id, "[$code]");
            $this->assertTrue(WalkInRule::isWalkIn($c), "[$code]");
        }
        Customer::query()->delete();
        $off = Customer::create(['code' => 'CASH', 'name' => 'Old', 'type' => 'retailer', 'terms' => 'cod', 'credit_limit' => 0, 'active' => false]);
        $var = Customer::create(['code' => 'CASH-WALKIN', 'name' => 'Variant', 'type' => 'retailer', 'terms' => 'cod', 'credit_limit' => 0, 'active' => true]);
        $this->assertSame($var->id, WalkInRule::cashCustomer()->id); // active beats inactive
        $var->update(['active' => false]);
        $this->assertSame($off->id, WalkInRule::cashCustomer()->id);  // falls back to any match
        $this->assertFalse(WalkInRule::isCashCode('CASHEW'));
        $this->assertFalse(WalkInRule::isCashCode('40505'));
    }

    public function test_a_single_flagged_walk_in_customer_is_used_when_no_account_is_called_cash(): void
    {
        Customer::query()->delete();
        $f = Customer::create(['code' => 'COUNTER', 'name' => 'Counter', 'type' => 'retailer', 'terms' => 'cod', 'credit_limit' => 0, 'active' => true, 'walk_in' => true]);
        $this->assertSame($f->id, WalkInRule::cashCustomer()->id);
        Customer::create(['code' => 'COUNTER2', 'name' => 'Counter 2', 'type' => 'retailer', 'terms' => 'cod', 'credit_limit' => 0, 'active' => true, 'walk_in' => true]);
        $this->assertNull(WalkInRule::cashCustomer()); // ambiguous: never guess
    }

    public function test_button_with_no_cash_customer_says_so_instead_of_doing_nothing(): void
    {
        Customer::query()->delete();
        Livewire::test(CustomerPicker::class, ['walkIn' => true])->call('selectWalkIn')
            ->assertSet('value', null)->assertSeeHtml('data-test="customer-walkin-notice"')->assertSee('No cash customer found');
    }

    public function test_customer_created_through_the_admin_form_is_a_walk_in_customer(): void
    {
        Customer::where('code', 'CASH')->update(['code' => 'OLD-DEMO-1', 'walk_in' => false]);
        Livewire::test(CreateCustomer::class)
            ->fillForm(['code' => 'CASH', 'name' => 'CASH WALK-IN CUSTOMER', 'type' => 'retailer', 'terms' => 'cod', 'active' => true])
            ->call('create')->assertHasNoFormErrors();
        $c = Customer::where('code', 'CASH')->firstOrFail();
        $this->assertTrue($c->isWalkIn());
        $this->assertSame($c->id, WalkInRule::cashCustomer()->id);
    }

    public function test_pos_blocks_regulated_cigarillos_for_the_cash_customer_and_greys_the_tiles(): void
    {
        $c = $this->adminCash('CASH', ['walk_in' => false]); // even with the switch off, code CASH is enough
        $wo = Product::where('sku', 'TST-CGR-001')->firstOrFail();
        $wo->update(['name' => 'White Owl Blunt 2pk', 'tax_class' => 'cigar']);
        $lw = Livewire::test(CounterPos::class)->set('customerId', $c->id)->call('openBrowse')->set('browseFilter', 'white owl');
        $tile = collect($lw->get('browseTiles'))->firstWhere('sku', 'TST-CGR-001');
        $this->assertNotNull($tile);
        $this->assertTrue($tile['restricted']);
        $lw->assertSeeHtml('data-restricted="1"')
            ->set('scan', 'TST-CGR-001')->call('add')->assertSet('cart', [])->assertSet('error', WalkInRule::message($wo->fresh()));
    }

    public function test_items_left_as_general_are_not_blocked_and_the_bulk_action_fixes_them(): void
    {
        $c = $this->adminCash();
        $wo = Product::where('sku', 'TST-CGR-001')->firstOrFail();
        $wo->update(['name' => 'White Owl Blunt 2pk', 'tax_class' => 'general']);
        $this->assertFalse(WalkInRule::blocks($c, $wo->fresh())); // this is what a mis-classed item looks like on live

        $this->assertSame(1, ProductResource::applyTaxClass([$wo], 'cigar'));
        $this->assertSame('cigar', $wo->fresh()->tax_class->value);
        $this->assertTrue(WalkInRule::blocks($c, $wo->fresh()));
    }

    public function test_migration_flags_code_variants_and_never_unflags(): void
    {
        Customer::query()->delete();
        $a = Customer::create(['code' => 'Cash', 'name' => 'A', 'type' => 'retailer', 'terms' => 'cod', 'credit_limit' => 0, 'active' => true]);
        $b = Customer::create(['code' => '40505', 'name' => 'B', 'type' => 'retailer', 'terms' => 'cod', 'credit_limit' => 0, 'active' => true, 'walk_in' => true]);
        $m = require database_path('migrations/2026_11_30_000002_phase2022_flag_cash_code_variants.php');
        $m->up();
        $m->up();
        $this->assertTrue((bool) $a->fresh()->walk_in);
        $this->assertTrue((bool) $b->fresh()->walk_in);
    }

    public function test_report_lists_products_by_name_with_their_class(): void
    {
        Product::where('sku', 'TST-CGR-001')->update(['name' => 'White Owl Blunt 2pk', 'tax_class' => 'general']);
        $this->artisan('erp:regulated-report', ['--like' => ['white owl']])
            ->expectsOutputToContain('Products matching "white owl": 1')
            ->expectsOutputToContain('NOT regulated (sellable to walk-in!)')
            ->assertSuccessful();
    }
}
