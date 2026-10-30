<?php

namespace Tests\Feature;

use App\Agent\AgentAccess;
use App\Filament\Pages\AgentAccessPage;
use App\Models\AgentAction;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** #35 Stage 1 (0.20.24): read-only MCP endpoint for an AI agent. */
class Phase2024AgentMcpTest extends TestCase
{
    use RefreshDatabase;

    private string $plain;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        $this->owner = User::factory()->create(['role' => 'owner']);
        Setting::put('agent_enabled', '1');
        $this->plain = app(AgentAccess::class)->issue(User::factory()->create(['role' => 'sales', 'name' => 'AI agent']), 'test', $this->owner)['plain'];
    }

    private function rpc(string $method, array $params = [], ?string $token = null, int $id = 1)
    {
        return $this->withHeader('Authorization', 'Bearer '.($token ?? $this->plain))
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
    }

    private function tool(string $name, array $args)
    {
        return $this->rpc('tools/call', ['name' => $name, 'arguments' => $args])->assertOk()->json('result');
    }

    public function test_handshake_and_tools_follow_the_users_permissions(): void
    {
        $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 't', 'version' => '1']])
            ->assertOk()->assertJsonPath('result.protocolVersion', '2025-06-18')->assertJsonPath('result.serverInfo.name', 'distrosku');
        $this->withHeader('Authorization', 'Bearer '.$this->plain)->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->assertStatus(202);

        $tools = collect($this->rpc('tools/list')->json('result.tools'))->pluck('name')->all();
        $this->assertContains('find_customer', $tools);
        $this->assertContains('quote_price', $tools);
        $this->assertNotContains('ar_aging', $tools);                           // sales role can't see reports
        $this->rpc('tools/call', ['name' => 'ar_aging', 'arguments' => []])->assertJsonPath('error.code', -32602);
    }

    public function test_lookups_return_data_and_are_logged(): void
    {
        $c = $this->tool('find_customer', ['query' => 'CTEST-KY']);
        $this->assertFalse($c['isError']);
        $this->assertSame('CTEST-KY', $c['structuredContent']['result'][0]['account']);

        $gen = Product::where('sku', 'TST-GEN-001')->firstOrFail();
        $p = $this->tool('find_product', ['query' => $gen->upc]);
        $this->assertSame('TST-GEN-001', $p['structuredContent']['result'][0]['sku']);

        $q = $this->tool('quote_price', ['customer' => 'CTEST-KY', 'product' => 'TST-GEN-001', 'qty' => 2]);
        $this->assertSame((float) $gen->price, (float) $q['structuredContent']['result']['price']);

        $h = $this->tool('customer_history', ['customer' => 'CTEST-KY']);
        $this->assertSame('CTEST-KY', $h['structuredContent']['result']['customer']);

        $bad = $this->tool('quote_price', ['customer' => 'NOPE', 'product' => 'TST-GEN-001']);
        $this->assertTrue($bad['isError']);
        $this->assertSame(5, AgentAction::count());
        $this->assertSame(1, AgentAction::where('ok', false)->count());
    }

    public function test_tools_cannot_change_data(): void
    {
        $before = [Customer::count(), Product::count(), \App\Models\SalesOrder::count()];
        $this->tool('stock_and_inbound', ['product' => 'TST-GEN-001']);
        $this->tool('find_product', ['query' => 'TEST']);
        $this->assertSame($before, [Customer::count(), Product::count(), \App\Models\SalesOrder::count()]);
    }

    public function test_off_switch_bad_tokens_and_revocation(): void
    {
        $this->rpc('ping', [], 'dsk_wrong')->assertStatus(401);
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertStatus(401);
        Setting::put('agent_enabled', '0');
        $this->rpc('ping')->assertStatus(403);
        Setting::put('agent_enabled', '1');
        $this->rpc('ping')->assertOk();
        app(AgentAccess::class)->revoke(\App\Models\AgentToken::firstOrFail(), $this->owner);
        $this->rpc('ping')->assertStatus(401);
        $this->assertSame(64, strlen(\App\Models\AgentToken::firstOrFail()->getRawOriginal('token_hash')));   // only the hash is stored
        $this->assertDatabaseMissing('agent_tokens', ['token_hash' => $this->plain]);
    }

    public function test_reports_for_an_admin_token(): void
    {
        $admin = app(AgentAccess::class)->issue($this->owner, 'owner', $this->owner)['plain'];
        $r = $this->rpc('tools/call', ['name' => 'ar_aging', 'arguments' => []], $admin)->assertOk()->json('result');
        $this->assertFalse($r['isError']);
    }

    public function test_settings_page_issues_a_token_once_and_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'sales']));
        $this->assertFalse(AgentAccessPage::canAccess());
        $this->actingAs($this->owner);
        $lw = Livewire::test(AgentAccessPage::class)->callAction('token', ['user_id' => $this->owner->id, 'name' => 'Claude for Michael', 'days' => 30]);
        $this->assertStringStartsWith('dsk_', $lw->get('newToken'));
        $lw->assertSeeHtml('data-test="new-token"');
        $this->assertDatabaseHas('activity_logs', ['event' => 'agent_token_created']);
    }
}
