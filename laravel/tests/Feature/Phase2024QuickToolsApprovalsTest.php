<?php

namespace Tests\Feature;

use App\Catalog\SkuApproval;
use App\Filament\Resources\SkuApprovalResource\Pages\ListSkuApprovals;
use App\Livewire\QuickTools;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Pos\PosPin;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** #45 (0.20.24): quick-tools PIN, new SKUs pending approval, tax class set by the approver, duplicate UPC check. */
class Phase2024QuickToolsApprovalsTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $clerk;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->token = Company::current()->rotateToken();
        $this->clerk = User::factory()->create(['name' => 'Pat Clerk', 'role' => 'sales']);
        app(PosPin::class)->set($this->clerk, '2580');
        $this->dept = Department::create(['code' => 'VAPE', 'name' => 'Vape']);
    }

    private function unlocked()
    {
        return Livewire::test(QuickTools::class, ['token' => $this->token])->set('pin', '2580')->call('unlock')->assertHasNoErrors();
    }

    private function createSku(string $name = 'Mystery Vape 5000', ?string $upc = '850000111222')
    {
        return $this->unlocked()->set('department_id', $this->dept->id)->set('name', $name)->set('price', '19.99')->set('upc', $upc)->call('create');
    }

    public function test_create_needs_a_pin_and_a_wrong_pin_is_refused(): void
    {
        Livewire::test(QuickTools::class, ['token' => $this->token])
            ->set('department_id', $this->dept->id)->set('name', 'X')->set('price', '1')->call('create')->assertHasErrors(['pin']);
        Livewire::test(QuickTools::class, ['token' => $this->token])->set('pin', '9753')->call('unlock')->assertHasErrors(['pin']);
        $this->assertNull(session(QuickTools::STAFF_SESSION));
        $this->unlocked()->assertSeeHtml('data-test="staff"')->assertSee('Pat Clerk');
    }

    public function test_pin_unlock_expires_after_idle_time(): void
    {
        $this->unlocked();
        $this->travel(QuickTools::STAFF_IDLE_SECONDS + 5)->seconds();
        Livewire::test(QuickTools::class, ['token' => $this->token])
            ->set('department_id', $this->dept->id)->set('name', 'Late')->set('price', '1')->call('create')->assertHasErrors(['pin']);
    }

    public function test_new_sku_is_pending_general_and_cannot_be_sold(): void
    {
        $this->createSku()->assertHasNoErrors()->assertSee('Waiting for manager approval');
        $p = Product::where('name', 'Mystery Vape 5000')->firstOrFail();
        $this->assertSame([SkuApproval::PENDING, $this->clerk->id, 'general'], [$p->approval_status, $p->requested_by, $p->tax_class->value]);

        $so = SalesOrder::create(['customer_id' => Customer::where('code', 'CTEST-KY')->value('id'), 'warehouse_id' => 1, 'order_date' => now()]);
        $this->expectException(ValidationException::class);
        $so->lines()->create(['product_id' => $p->id, 'qty' => 1, 'unit_price' => '19.99']);
    }

    public function test_duplicate_upc_is_refused_including_extra_barcodes_and_ean_form(): void
    {
        $gen = Product::where('sku', 'TST-GEN-001')->firstOrFail();
        $this->createSku('Dup', $gen->upc)->assertHasErrors(['upc']);
        $gen->barcodes()->create(['code' => '20000000000999']);
        $this->createSku('Dup2', '20000000000999')->assertHasErrors(['upc']);
        $this->createSku('Dup3', '0'.$gen->upc)->assertHasErrors(['upc']);           // same code read as EAN-13
        $this->assertSame(0, Product::whereIn('name', ['Dup', 'Dup2', 'Dup3'])->count());
    }

    public function test_count_is_named_by_the_pin_not_typed(): void
    {
        $this->unlocked()->set('tab', 'count')->set('cq', 'TST-GEN-001')->call('countSearch')
            ->set('count_warehouse_id', 1)->set('count_qty', '4')->set('count_by', 'Somebody Else')->call('submitCount')->assertHasNoErrors();
        $this->assertSame('Pat Clerk', \App\Models\QuickCount::latest('id')->value('counted_by'));
    }

    public function test_admin_approves_with_tax_class_and_it_becomes_sellable(): void
    {
        $this->createSku();
        $p = Product::where('name', 'Mystery Vape 5000')->firstOrFail();
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner);

        Livewire::test(ListSkuApprovals::class)->assertCanSeeTableRecords([$p])
            ->callTableAction('approve', $p, data: ['tax_class' => 'vapor', 'price' => '21.50', 'note' => 'checked'])->assertHasNoTableActionErrors();
        $p->refresh();
        $this->assertNull($p->approval_status);
        $this->assertSame(['vapor', '21.5000', $owner->id], [$p->tax_class->value, (string) $p->price, $p->approved_by]);
        $this->assertTrue((bool) $p->pact_covered);

        $so = SalesOrder::create(['customer_id' => Customer::where('code', 'CTEST-KY')->value('id'), 'warehouse_id' => 1, 'order_date' => now()]);
        $this->assertNotNull($so->lines()->create(['product_id' => $p->id, 'qty' => 1, 'unit_price' => '21.50'])->id);
        $this->assertDatabaseHas('activity_logs', ['event' => 'sku_approved']);
    }

    public function test_reject_archives_and_only_admins_decide(): void
    {
        $this->createSku();
        $p = Product::where('name', 'Mystery Vape 5000')->firstOrFail();

        $this->actingAs(User::factory()->create(['role' => 'sales']));
        $this->assertFalse(\App\Filament\Resources\SkuApprovalResource::canViewAny());
        try {
            app(SkuApproval::class)->approve($p, ['tax_class' => 'general'], auth()->user());
            $this->fail('sales approved a SKU');
        } catch (\App\Exceptions\WorkflowException) {
        }

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        Livewire::test(ListSkuApprovals::class)->callTableAction('reject', $p, data: ['note' => 'duplicate of an existing item'])->assertHasNoTableActionErrors();
        $p->refresh();
        $this->assertSame(SkuApproval::REJECTED, $p->approval_status);
        $this->assertTrue($p->isArchived());
    }

    public function test_migration_is_rerunnable_and_existing_products_are_unaffected(): void
    {
        $m = require database_path('migrations/2026_12_02_000003_phase2024_quick_tools_approvals.php');
        $m->up();
        $this->assertSame(0, Product::whereNotNull('approval_status')->count());
    }
}
