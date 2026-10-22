<?php

namespace Tests\Feature;

use App\Livewire\Portal\Catalog;
use App\Models\Customer;
use App\Models\CustomerContact;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/** Issue #8: portal Shop / Buy again as a segmented control; the cart bar actually sticks. */
class Phase207PortalTabsCartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $k = CustomerContact::create(['customer_id' => Customer::where('code', 'CTEST-KY')->value('id'), 'name' => 'Pat Buyer', 'email' => 'tabs@example.test']);
        $k->setPasswordFromLink('Secret12345');
        Auth::guard('portal')->login($k->fresh());
    }

    public function test_tabs_mark_the_active_view(): void
    {
        Livewire::test(Catalog::class)
            ->assertSeeHtml('role="tablist"')
            ->assertSeeHtml('role="tab" class="on" aria-selected="true" wire:click="$set(\'view\', \'shop\')"')
            ->set('view', 'again')
            ->assertSeeHtml('role="tab" class="on" aria-selected="true" wire:click="$set(\'view\', \'again\')"')
            ->assertSeeHtml('role="tab" class="" aria-selected="false" wire:click="$set(\'view\', \'shop\')"');
    }

    public function test_layout_keeps_sticky_working(): void
    {
        $html = $this->get('/portal')->assertOk()->getContent();

        // overflow-x on <body> makes body a scroll container and silently disables position:sticky.
        $this->assertStringNotContainsString('html,body{overflow-x:hidden}', $html);
        $this->assertStringContainsString('html{overflow-x:hidden}', $html);
        $this->assertStringContainsString('.cartbar{position:sticky;bottom:0', $html);
        $this->assertStringContainsString('data-test="cartbar"', $html);
    }
}
