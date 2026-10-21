<?php

namespace Tests\Feature;

use App\Catalog\CatalogService;
use App\Catalog\ImageService;
use App\Filament\Pages\CompanyBranding;
use App\Filament\Pages\PlatformCompany;
use App\Filament\Pages\PlatformUsers;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Livewire\QuickTools;
use App\Models\Company;
use App\Models\Department;
use App\Models\PriceSubmission;
use App\Models\Product;
use App\Models\SharedProduct;
use App\Models\User;
use Database\Seeders\CoreSeeder;
use Database\Seeders\Phase4Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class Phase4Test extends TestCase
{
    use RefreshDatabase;

    private Department $elec;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(CoreSeeder::class);
        $this->elec = Department::create(['code' => 'elec', 'name' => 'Electronics']);
    }

    private function svc(): CatalogService
    {
        return app(CatalogService::class);
    }

    public function test_sku_codes_per_department_continue_and_never_collide(): void
    {
        $this->assertSame('ELEC', $this->elec->code);
        Product::factory()->create(['sku' => 'ELEC-0041']);
        $legacy = Product::factory()->create(['sku' => 'LEGACY-1']);
        $a = $this->svc()->create(['department_id' => $this->elec->id, 'name' => 'Phone Charger', 'price' => '9.99', 'upc' => '0 12345 67890 5']);
        $this->assertSame('ELEC-0042', $a->sku);
        $this->assertSame('ELEC-0042', $a->barcodeValue());
        $this->assertSame('012345678905', $a->upc);
        Product::factory()->create(['sku' => 'ELEC-0043']); // someone typed the next code manually
        $this->assertSame('ELEC-0044', $this->svc()->nextSku($this->elec));
        $toys = Department::create(['code' => 'TOY', 'name' => 'Toys']);
        $this->assertSame('TOY-0001', $this->svc()->nextSku($toys));
        $this->assertSame('LEGACY-1', $legacy->fresh()->sku);
        $codes = collect(range(1, 20))->map(fn () => $this->svc()->nextSku($toys));
        $this->assertSame(20, $codes->unique()->count());
    }

    public function test_shared_product_link_and_research_stats(): void
    {
        $a = $this->svc()->create(['department_id' => $this->elec->id, 'name' => 'USB-C  Cable 6ft', 'price' => '10']);
        $b = $this->svc()->create(['department_id' => $this->elec->id, 'name' => 'usb c cable 6FT', 'price' => '20']);
        $this->assertSame($a->shared_product_id, $b->shared_product_id);
        $this->assertSame(1, SharedProduct::count());
        $row = CatalogService::researchQuery()->first();
        $this->assertEquals(15, (float) $row->avg_price);
        $this->assertEquals(10, (float) $row->min_price);
        $this->assertEquals(20, (float) $row->max_price);
        // seeder links legacy products once (idempotent)
        Product::factory()->create(['name' => 'Legacy thing']);
        (new Phase4Seeder)->run();
        (new Phase4Seeder)->run();
        $this->assertSame(0, Product::whereNull('shared_product_id')->count());
        $this->assertSame(Product::count(), PriceSubmission::count());
    }

    public function test_images_are_resized_to_400_and_bad_files_rejected(): void
    {
        $img = UploadedFile::fake()->image('big.jpg', 1200, 600);
        $p = $this->svc()->create(['department_id' => $this->elec->id, 'name' => 'TV', 'price' => '100'], $img);
        Storage::disk('public')->assertExists($p->image_path);
        [$w, $h] = getimagesize(Storage::disk('public')->path($p->image_path));
        $this->assertSame([400, 200], [$w, $h]);
        $this->expectException(\RuntimeException::class);
        app(ImageService::class)->store(UploadedFile::fake()->create('evil.php', 10, 'image/png'), 'catalog');
    }

    public function test_admin_creates_sku_in_filament_with_generated_code(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(CreateProduct::class)->fillForm([
            'department_id' => $this->elec->id, 'sku' => '', 'name' => 'Speaker', 'price' => '25', 'cost' => '10', 'tax_class' => 'general', 'unit' => 'each',
        ])->call('create')->assertHasNoFormErrors();
        $p = Product::where('name', 'Speaker')->first();
        $this->assertSame('ELEC-0001', $p->sku);
        $this->assertNotNull($p->shared_product_id);
        $this->get('/admin/products')->assertOk();
        $this->get(route('catalog.labels', ['ids' => $p->id]))->assertOk()->assertSee('ELEC-0001')->assertSee('<svg', false);
        $pdf = $this->get(route('catalog.labels', ['ids' => $p->id, 'format' => 'pdf']));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $csv = $this->get(route('catalog.csv'))->streamedContent();
        $this->assertStringContainsString('ELEC-0001,ELEC-0001,Speaker', $csv);
        $this->assertStringStartsWith('%PDF', $this->get(route('catalog.pdf'))->getContent());
    }

    public function test_role_access(): void
    {
        foreach (['sales', 'warehouse'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get('/admin/products')->assertOk();
            $this->get('/admin/research')->assertOk();
            $this->get(route('catalog.csv'))->assertOk();
            $this->get('/admin/products/create')->assertForbidden();
            $this->get('/admin/departments/create')->assertForbidden();
            $this->get('/admin/branding')->assertForbidden();
            $this->get('/admin/platform/users')->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'driver']));
        $this->get(route('catalog.csv'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/admin/departments/create')->assertOk();
        $this->get('/admin/branding')->assertOk();
        $this->get('/admin/platform/company')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'brandworx_admin']));
        foreach (['/admin/platform/company', '/admin/platform/users', '/admin/platform/catalog'] as $u) {
            $this->get($u)->assertOk();
        }
    }

    public function test_public_quick_tools_token_create_lookup_and_rotation(): void
    {
        $this->get('/q/'.str_repeat('a', 48))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(CompanyBranding::class)->callAction('token');
        $token = Company::current()->public_token;
        $this->assertSame(48, strlen($token));
        auth()->logout();

        $this->get("/q/{$token}")->assertOk()->assertSee('Create SKU')->assertSee('html5-qrcode.min.js');
        $this->assertFileExists(public_path('vendor/html5-qrcode/html5-qrcode.min.js'));
        Livewire::test(QuickTools::class, ['token' => $token])
            ->set('department_id', $this->elec->id)->set('name', 'Earbuds')->set('price', '15.5')->set('upc', '123456789012')
            ->set('image', UploadedFile::fake()->image('e.png', 800, 800))
            ->call('create')->assertHasNoErrors()->assertSee('ELEC-0001');
        $p = Product::where('sku', 'ELEC-0001')->first();
        $this->assertSame('quick_tools', PriceSubmission::where('product_id', $p->id)->value('source'));
        Livewire::test(QuickTools::class, ['token' => $token])->set('tab', 'lookup')->set('q', '123456789012')->call('search')->assertSee('Earbuds');
        Livewire::test(QuickTools::class, ['token' => $token])->set('tab', 'lookup')->set('q', 'earb')->call('search')->assertSee('ELEC-0001');
        $this->get("/q/{$token}/labels?ids={$p->id}")->assertOk()->assertSee('ELEC-0001');
        $this->get("/q/{$token}/labels?ids={$p->id}&format=pdf")->assertOk();

        Livewire::test(QuickTools::class, ['token' => $token])->set('department_id', $this->elec->id)->set('name', 'X')->set('price', '1')
            ->set('image', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'))->call('create')->assertHasErrors('image');
        Livewire::test(QuickTools::class, ['token' => $token])->set('department_id', $this->elec->id)->set('name', '')->set('price', 'abc')->call('create')->assertHasErrors(['name', 'price']);

        Company::current()->rotateToken();
        $this->get("/q/{$token}")->assertNotFound();
        $this->get("/q/{$token}/labels?ids={$p->id}")->assertForbidden();
    }

    public function test_quick_tools_rate_limited(): void
    {
        $token = Company::current()->rotateToken();
        $lw = Livewire::test(QuickTools::class, ['token' => $token])->set('tab', 'lookup')->set('q', 'zz');
        for ($i = 0; $i < 60; $i++) {
            $lw->call('search');
        }
        $lw->call('search')->assertHasErrors('rate');
    }

    public function test_suspension_blocks_logins_and_public_link_except_brandworx_admin(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'password' => Hash::make('owner-password-1')]);
        $bwx = User::factory()->create(['role' => 'brandworx_admin']);
        $token = Company::current()->rotateToken();
        $this->actingAs($bwx);
        Livewire::test(PlatformCompany::class)->callAction('suspend', ['reason' => 'Unpaid invoice']);
        $this->assertTrue(Company::current()->suspended);
        $this->get('/admin')->assertOk(); // brandworx_admin still in
        $this->get('/admin/login')->assertRedirect();
        auth()->logout();

        $this->get('/admin/login')->assertOk()->assertSee('This account is suspended');
        $this->assertFalse($owner->canAccessPanel(filament()->getPanel('admin')));
        $this->actingAs($owner)->get('/admin')->assertStatus(403)->assertSee('Account suspended');
        $this->assertGuest();
        $this->get("/q/{$token}")->assertOk()->assertSee('quick-tools link is disabled')->assertDontSee('Create SKU');
        $this->get('/q/'.$token.'/labels?ids=1')->assertForbidden();

        $this->actingAs($bwx);
        Livewire::test(PlatformCompany::class)->callAction('reactivate');
        $this->actingAs($owner)->get('/admin')->assertOk();
    }

    public function test_platform_create_user_and_reset_password(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'brandworx_admin']));
        Livewire::test(PlatformUsers::class)->callTableAction('create', null, ['name' => 'New Clerk', 'email' => 'clerk@example.test', 'role' => 'sales', 'password' => 'a-long-password-1'])
            ->assertHasNoTableActionErrors();
        $u = User::where('email', 'clerk@example.test')->first();
        $this->assertTrue(Hash::check('a-long-password-1', $u->password));
        Livewire::test(PlatformUsers::class)->callTableAction('resetPassword', $u, ['password' => 'another-password-2', 'password_confirmation' => 'another-password-2'])
            ->assertHasNoTableActionErrors();
        $this->assertTrue(Hash::check('another-password-2', $u->fresh()->password));
    }

    public function test_brand_logo_in_panel_header_and_public_page(): void
    {
        $c = Company::current();
        $c->update(['logo_path' => app(ImageService::class)->store(UploadedFile::fake()->image('logo.png', 900, 300), 'branding')]);
        $this->actingAs(User::factory()->create(['role' => 'sales']));
        $this->get('/admin/products')->assertOk()->assertSee($c->logoUrl(), false);
        $token = $c->rotateToken();
        auth()->logout();
        $this->get("/q/{$token}")->assertSee($c->fresh()->logoUrl(), false);
    }
}
