<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FooterBrandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
    }

    private function assertFooter($res): void
    {
        $res->assertOk()->assertSee('data-test="powered-by"', false)->assertSee('Powered by DistroSKU')->assertSee('alt="DistroSKU"', false)
            ->assertSee('href="https://distrosku.com" target="_blank" rel="noopener"', false)
            ->assertSee('images/distrosku-logo.png', false);
    }

    public function test_footer_on_admin_login_dashboard_portal_and_quick_tools(): void
    {
        $this->assertFileExists(public_path('images/distrosku-logo.png'));
        $this->assertFooter($this->get('/admin/login'));
        $this->assertFooter($this->get('/portal/login'));
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $this->assertFooter($this->get('/admin'));
        auth()->logout();
        $c = Company::current();
        $c->public_token = bin2hex(random_bytes(24));
        $c->save();
        $this->assertFooter($this->get('/q/'.$c->public_token));
    }

    private function pdfText(string $bytes): string
    {
        $this->assertStringStartsWith('%PDF', $bytes);
        $f = tempnam(sys_get_temp_dir(), 'ds');
        file_put_contents($f, $bytes);
        exec('pdftotext '.escapeshellarg($f).' - 2>/dev/null', $out, $rc);
        @unlink($f);
        if ($rc !== 0) {
            $this->markTestSkipped('pdftotext not available');
        }
        return implode("\n", $out);
    }

    public function test_footer_on_pos_errors_and_printables(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $this->assertFooter($this->get('/pos'));
        $this->assertFooter($this->get('/admin/customers'));
        $p = Product::query()->first();
        $this->get(route('catalog.labels', ['ids' => $p->id]))->assertOk()->assertDontSee('Powered by DistroSKU')->assertDontSee('distrosku-powered');
        $this->get('/no-such-page-xyz')->assertNotFound()->assertSee('Powered by DistroSKU')->assertSee('alt="DistroSKU"', false);

        $this->assertStringNotContainsString('Powered by DistroSKU', $this->pdfText($this->get(route('docs.statement', Customer::query()->first()))->getContent()));
        $this->assertStringNotContainsString('Powered by DistroSKU', $this->pdfText($this->get(route('catalog.pdf'))->getContent()));
        $this->assertStringNotContainsString('Powered by DistroSKU', $this->pdfText($this->get(route('catalog.labels', ['ids' => $p->id, 'format' => 'pdf']))->getContent()));
    }

    public function test_footer_in_error_views_and_mail_layout(): void
    {
        foreach (['403', '404', '500', '503'] as $code) {
            $this->assertStringContainsString('Powered by DistroSKU', view("errors.$code")->render(), $code);
        }
        $html = (string) (new \Illuminate\Notifications\Messages\MailMessage)->line('Hi')->render();
        $this->assertStringContainsString('Powered by DistroSKU', $html);
        $this->assertStringContainsString('alt="DistroSKU"', $html);
    }

    public function test_print_css_hides_screen_footer_and_dompdf_uses_real_web_root(): void
    {
        $html = $this->get('/admin/login')->getContent();
        $this->assertMatchesRegularExpression('/@media print\s*\{\s*\.distrosku-powered\s*\{\s*display:\s*none !important/', $html);
        $this->assertSame(public_path(), config('dompdf.public_path'));
        $this->assertNotFalse(realpath(config('dompdf.public_path')));
        foreach (['docs.invoice', 'docs.statement', 'catalog.catalog-pdf', 'catalog.labels-pdf', 'catalog.labels', 'reports.pdf', 'reports.management-pdf'] as $v) {
            $this->assertStringNotContainsString('powered-by', file_get_contents(view()->getFinder()->find($v)), $v);
        }
    }
}
