<?php

namespace Tests\Feature;

use App\Health\HealthCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** #22 (0.20.24): /health for the uptime monitor: database, scheduler heartbeat, stuck queue. */
class Phase2024HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_ok_when_everything_runs(): void
    {
        HealthCheck::beat();
        $this->get('/health')->assertOk()->assertSeeText('OK')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_scheduler_stopped_is_a_503(): void
    {
        Cache::forget(HealthCheck::HEARTBEAT_KEY);
        $this->get('/health')->assertStatus(503)->assertSeeText('FAIL scheduler');
        HealthCheck::beat();
        $this->travel(HealthCheck::SCHEDULER_MAX_AGE + 60)->seconds();
        $this->get('/health')->assertStatus(503)->assertSeeText('scheduler');
    }

    public function test_stuck_queue_is_a_503_and_no_details_leak(): void
    {
        HealthCheck::beat();
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subMinutes(30)->timestamp, 'created_at' => now()->subMinutes(30)->timestamp]);
        $res = $this->get('/health')->assertStatus(503);
        $this->assertSame('FAIL queue', $res->getContent());
    }

    public function test_scheduler_registers_the_heartbeat(): void
    {
        $names = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->description)->all();
        $this->assertContains('health-heartbeat', $names);
    }
}
