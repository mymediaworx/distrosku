<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Issue #6: every admin resource's page title matches its sidebar label, with acronyms intact. */
class Phase207NavTitleLabelsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, class-string> */
    private function resources(): array
    {
        return collect(glob(app_path('Filament/Resources/*Resource.php')))
            ->map(fn ($f) => 'App\\Filament\\Resources\\'.basename($f, '.php'))
            ->filter(fn ($c) => class_exists($c))->values()->all();
    }

    public function test_page_title_matches_sidebar_label_for_every_resource(): void
    {
        $mismatches = [];
        foreach ($this->resources() as $c) {
            $nav = $c::getNavigationLabel();
            $title = $c::getTitleCasePluralModelLabel();
            if (strcasecmp($nav, $title) !== 0) {
                $mismatches[] = class_basename($c).": sidebar '{$nav}' vs title '{$title}'";
            }
        }
        $this->assertSame([], $mismatches, "New resources should set \$pluralModelLabel to match their sidebar label.\n".implode("\n", $mismatches));
    }

    public function test_acronyms_are_not_title_cased_away(): void
    {
        $bad = [];
        foreach ($this->resources() as $c) {
            foreach ([$c::getTitleCasePluralModelLabel(), $c::getTitleCaseModelLabel()] as $label) {
                if (preg_match('/\b(Pact|Msa|Pos|Pin|Ky|Ghl|Upc|Sku|Coa)\b/', $label)) {
                    $bad[] = class_basename($c).": {$label}";
                }
            }
        }
        $this->assertSame([], $bad);
    }

    public function test_renamed_pages_render_with_the_sidebar_wording(): void
    {
        $this->seed(CoreSeeder::class);
        $this->actingAs(User::factory()->create(['role' => 'owner']));

        $this->get('/admin/shipments')->assertOk()->assertSee('Shipments')->assertDontSee('Cartons');
        $this->get('/admin/pos-pin-logins')->assertOk()->assertSee('POS PIN Log');
        $this->get('/admin/msa-directory-imports')->assertOk()->assertSee('MSA State Directories');
        $this->get('/admin/pact-reports')->assertOk()->assertSee('PACT Act Reports');
        $this->get('/admin/ship-tos')->assertOk()->assertSee('Ship-tos');
        $this->get('/admin/price-rules')->assertOk()->assertSee('Price Lists');
    }
}
