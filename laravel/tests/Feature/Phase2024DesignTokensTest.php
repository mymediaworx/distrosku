<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Support\Tokens;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** #10 (0.20.24): shared design tokens for the standalone screens, brand color from Company Settings, empty states. */
class Phase2024DesignTokensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
    }

    public function test_layouts_use_tokens_not_hard_coded_brand_colors(): void
    {
        foreach (['pos', 'portal', 'quick', 'warehouse', 'driver'] as $l) {
            $src = file_get_contents(resource_path("views/layouts/{$l}.blade.php"));
            $this->assertStringContainsString("@include('partials.tokens'", $src, $l);
            $this->assertStringNotContainsString('#1e3a8a', $src, $l);
            $this->assertStringNotContainsString('#0f766e', $src, $l);
            $this->assertStringContainsString('var(--brand)', $src, $l);
        }
    }

    public function test_defaults_and_company_brand_color(): void
    {
        $this->assertStringContainsString('--brand:#1e3a8a', Tokens::css());
        $this->assertStringContainsString('--brand:#0f766e', Tokens::css(Tokens::DRIVER_BRAND));

        Company::current()->update(['brand_color' => '#7C2D12']);
        $this->assertStringContainsString('--brand:#7c2d12', Tokens::css());
        $this->assertStringContainsString('--brand:#7c2d12', Tokens::css(Tokens::DRIVER_BRAND)); // one brand everywhere once set
        $this->assertStringContainsString('--on-brand:#ffffff', Tokens::css());

        Company::current()->update(['brand_color' => '#fde68a']);                                 // light yellow -> dark text
        $this->assertStringContainsString('--on-brand:#111111', Tokens::css());

        Company::current()->update(['brand_color' => 'red;}x{']);                               // never injected
        $this->assertStringContainsString('--brand:#1e3a8a', Tokens::css());
    }

    public function test_pos_page_carries_the_brand_color(): void
    {
        Company::current()->update(['brand_color' => '#7c2d12']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/pos')->assertOk()->assertSee('--brand:#7c2d12', false);
    }

    public function test_settings_saves_brand_color_and_rejects_bad_values(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(Settings::class)->fillForm(['company_name' => 'Vega', 'company_brand_color' => '#14532d', 'portal_enabled' => true])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('#14532d', Company::current()->fresh()->brand_color);

        Livewire::test(Settings::class)->fillForm(['company_name' => 'Vega', 'company_brand_color' => 'url(x)', 'portal_enabled' => true])
            ->call('save')->assertHasFormErrors(['company_brand_color']);
    }

    public function test_empty_list_shows_the_next_step(): void
    {
        Product::query()->delete();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(ListProducts::class)->assertSee('No products yet')->assertSee('Settings > Imports');
    }
}
