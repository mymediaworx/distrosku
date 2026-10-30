<?php

namespace Tests\Feature;

use App\Dba\BackupService;
use App\Dba\InstanceState;
use App\Dba\RulesApplier;
use App\Dba\Signature;
use App\Models\Customer;
use App\Models\EligibilityRule;
use App\Models\LicenseType;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Models\User;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** 0.20.8: instance-manager link: HMAC endpoints, suspend switch, backup/restore, global rule push. */
class Phase208InstanceManagerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'xxxxxxxx-test-only-xxxxxxxx-not-a-real-key-xxxxxxxx';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CoreSeeder::class, DemoSeeder::class]);
        Setting::put('dba_secret', self::SECRET);
        $this->dir = sys_get_temp_dir().'/erp-bk-test-'.uniqid();
        config(['erp.dba.backup_path' => $this->dir, 'erp.dba.instance' => 'vegatest1']);
        BackupService::$useMysqldump = false;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function signed(string $ep, array $body = [], ?int $ts = null, ?string $secret = null, ?array $headers = null)
    {
        $json = json_encode($body);
        $h = $headers ?? Signature::headers($secret ?? self::SECRET, 'POST', '/dba-api/'.$ep, $json, $ts);

        return $this->call('POST', '/dba-api/'.$ep, [], [], [], $this->transformHeadersToServerVars($h + ['Content-Type' => 'application/json', 'Accept' => 'application/json']), $json);
    }

    public function test_status_requires_a_valid_fresh_unreplayed_signature(): void
    {
        $this->signed('status')->assertOk()->assertJsonPath('version', config('erp.version'))->assertJsonPath('suspended', false)->assertJsonPath('instance', 'vegatest1');
        $this->signed('status', [], null, 'wrong-secret-wrong-secret-wrong-secret')->assertStatus(401)->assertJsonPath('error', 'bad signature');
        $this->signed('status', [], time() - 301)->assertStatus(401)->assertJsonPath('error', 'stale timestamp');
        $this->signed('status', [], time() + 400)->assertStatus(401)->assertJsonPath('error', 'stale timestamp');
        $this->postJson('/dba-api/status')->assertStatus(401)->assertJsonPath('error', 'missing signature headers');
        $h = Signature::headers(self::SECRET, 'POST', '/dba-api/status', json_encode([]));
        $this->signed('status', [], null, null, $h)->assertOk();
        $this->signed('status', [], null, null, $h)->assertStatus(401)->assertJsonPath('error', 'replayed request');
        // body tampering breaks the signature
        $h2 = Signature::headers(self::SECRET, 'POST', '/dba-api/suspend', json_encode(['reason' => 'a']));
        $this->signed('suspend', ['reason' => 'b'], null, null, $h2)->assertStatus(401);
        $this->assertFalse(InstanceState::suspended());
    }

    public function test_unlinked_instance_refuses_manager_calls(): void
    {
        Setting::put('dba_secret', null);
        config(['erp.dba.secret' => null]);
        $this->signed('status')->assertStatus(503);
    }

    public function test_suspend_blocks_every_surface_without_changing_data_and_resume_restores(): void
    {
        $before = Customer::count();
        $this->get('/admin/login')->assertOk();
        $this->signed('suspend', ['reason' => 'Billing hold', 'by' => 'michael@brandworx.ai'])->assertOk();
        $this->assertTrue(InstanceState::suspended());
        foreach (['/admin/login', '/admin', '/portal/login', '/pos', '/driver', '/warehouse'] as $url) {
            $this->get($url)->assertStatus(503)->assertSee('This account is suspended')->assertSee('Billing hold');
        }
        $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]));
        $this->get('/admin')->assertStatus(503);
        // manager can still talk to it
        $this->signed('status')->assertOk()->assertJsonPath('suspended', true);
        // scheduled jobs (except the backup and the #22 health heartbeat, which only says cron is alive) are paused
        $events = collect(app(Schedule::class)->events());
        $this->assertTrue($events->reject(fn ($e) => str_contains((string) $e->command, 'erp:backup') || $e->description === 'health-heartbeat')->every(fn ($e) => ! $e->filtersPass($this->app)));
        $this->assertSame($before, Customer::count());
        $this->signed('resume', ['by' => 'michael@brandworx.ai'])->assertOk();
        $this->assertNotSame(503, $this->get('/admin')->status());
        $this->assertTrue(collect(app(Schedule::class)->events())->contains(fn ($e) => $e->filtersPass($this->app)));
        $this->assertDatabaseHas('activity_logs', ['event' => 'dba_suspended']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'dba_resumed']);
    }

    public function test_backup_restore_round_trip_with_checksums(): void
    {
        $b = app(BackupService::class);
        File::ensureDirectoryExists(storage_path('app/dba-test'));
        file_put_contents(storage_path('app/dba-test/logo.txt'), "original\x00\xff");
        $c = Customer::first();
        $name = $c->name;
        $count = Customer::count();

        $res = $this->signed('backup', ['by' => 'tester'])->assertOk()->json('backup');
        $this->assertStringStartsWith('vegatest1-', $res['file']);
        $this->assertFileExists($this->dir.'/'.$res['file'].'.sha256');
        $this->assertSame($res['sha256'], hash_file('sha256', $this->dir.'/'.$res['file']));
        $this->assertArrayNotHasKey('path', $res);

        // damage the data
        $c->forceFill(['name' => 'CHANGED'])->save();
        Customer::where('id', '!=', $c->id)->limit(1)->get()->each->forceDelete();
        file_put_contents(storage_path('app/dba-test/logo.txt'), 'changed');
        Setting::put('dba_secret', self::SECRET); // the link must survive the restore

        $r = $b->restore($res['file'], false, true, 'tester');
        $this->assertSame($name, Customer::find($c->id)->name);
        $this->assertSame($count, Customer::count());
        $this->assertSame("original\x00\xff", file_get_contents(storage_path('app/dba-test/logo.txt')));
        $this->assertNotNull($r['safety_backup']);
        $this->assertFileExists($this->dir.'/'.$r['safety_backup']);
        $this->assertSame(self::SECRET, Setting::get('dba_secret'));
        $this->assertCount(2, $this->signed('backups', ['verify' => true])->json('backups'));
        $this->assertSame(['verified', 'verified'], array_column($b->list(true), 'status'));

        // corrupt archive is refused
        file_put_contents($this->dir.'/'.$res['file'], 'x', FILE_APPEND);
        $this->expectExceptionMessage('Checksum mismatch');
        $b->restore($res['file'], false, false);
    }

    public function test_restore_into_another_instance_from_a_full_path(): void
    {
        $b = app(BackupService::class);
        $src = $b->create('manual');
        Customer::query()->limit(1)->get()->each->forceDelete();
        config(['erp.dba.instance' => 'restore-test', 'erp.dba.backup_path' => $this->dir.'/other']);
        $r = app(BackupService::class)->restore($src['path'], false, false);
        $this->assertSame('vegatest1', $r['from_instance']);
        $this->assertGreaterThan(0, $r['rows']);
        // the restore endpoint itself only accepts a bare file name + confirmation
        $this->signed('restore', ['file' => $src['path'], 'confirm' => 'restore-test'])->assertStatus(422);
        $this->signed('restore', ['file' => basename($src['path']), 'confirm' => 'wrong'])->assertStatus(422);
    }

    public function test_retention_keeps_14_daily_and_8_weekly(): void
    {
        $b = app(BackupService::class);
        File::ensureDirectoryExists($this->dir);
        $start = now()->utc()->startOfDay()->subDays(119);
        for ($d = 0; $d < 120; $d++) {
            foreach (['nightly', 'manual'] as $i => $t) {
                $f = $this->dir.'/vegatest1-'.$start->copy()->addDays($d)->addHours(1 + $i)->format('Ymd-His').'-'.$t.'.zip';
                file_put_contents($f, 'z');
                file_put_contents($f.'.sha256', hash('sha256', 'z').'  '.basename($f));
            }
        }
        $b->prune();
        $left = $b->list();
        $this->assertCount(22, $left);
        $this->assertSame(22, count(glob($this->dir.'/*.sha256')));
        $this->assertSame('manual', $left[0]['trigger']); // newest of the newest day kept
    }

    public function test_rule_push_is_idempotent_never_deletes_and_locks_managed_rows(): void
    {
        $a = app(RulesApplier::class);
        $set = $a->export(1);
        $this->assertNotEmpty($set['sets']['license_types']);
        $this->assertNotEmpty($set['sets']['eligibility_rules']);
        $this->assertNotEmpty($set['sets']['tax_rates']);
        $localRule = EligibilityRule::create(['name' => 'Local only', 'level' => 'state', 'state' => 'KY', 'effective_from' => '2026-01-01']);
        $licenses = LicenseType::count();

        $set['version'] = 2;
        $set['sets']['license_types'][] = ['code' => 'lt:ky_vape_retail', 'overridable' => false, 'data' => ['code' => 'ky_vape_retail', 'name' => 'KY Vape Retail']];
        $first = $this->signed('rules/apply', ['ruleset' => $set, 'by' => 'tester'])->assertOk()->json('result');
        $this->assertSame(1, $first['totals']['create'], json_encode(collect($first['sets'])->map(fn ($r) => collect($r)->where('action', 'create')->values())));
        $this->assertSame(0, $first['totals']['kept_local']);
        $this->assertSame(2, $a->currentVersion());
        $this->assertSame($licenses + 1, LicenseType::count());

        $again = $a->apply($set);
        $this->assertSame(0, $again['totals']['create'] + $again['totals']['update'], json_encode($again));
        $this->assertSame($licenses + 1, LicenseType::count());

        // nothing removed: a later set without a row leaves it (and local data) alone
        $smaller = $set;
        $smaller['version'] = 3;
        $smaller['sets']['license_types'] = [];
        $a->apply($smaller);
        $this->assertSame($licenses + 1, LicenseType::count());
        $this->assertNotNull(EligibilityRule::find($localRule->id));

        // locked locally
        $lt = LicenseType::where('managed_code', 'lt:ky_vape_retail')->first();
        try {
            $lt->update(['name' => 'Edited']);
            $this->fail('managed row was editable');
        } catch (ValidationException) {
        }
        $this->expectException(ValidationException::class);
        $lt->delete();
    }

    public function test_overridable_rows_keep_local_edits_and_preview_reports_diff(): void
    {
        $a = app(RulesApplier::class);
        $set = $a->export(1);
        $a->apply($set);
        $rule = EligibilityRule::whereNotNull('managed_code')->first();
        $this->assertTrue((bool) $rule->managed_overridable);
        $localEnabled = ! (bool) $rule->enabled;
        $rule->update(['enabled' => $localEnabled]);
        $this->assertTrue((bool) $rule->fresh()->local_override);

        $tax = TaxRate::whereNotNull('managed_code')->first();
        $set['version'] = 2;
        foreach ($set['sets']['tax_rates'] as &$t) {
            if ($t['code'] === $tax->managed_code) {
                $t['data']['rate'] = (float) $tax->rate + 1;
            }
        }
        unset($t);
        $p = $this->signed('rules/preview', ['ruleset' => $set])->assertOk()->json('preview');
        $this->assertSame(1, $p['totals']['update']);
        $this->assertGreaterThanOrEqual(1, $p['totals']['kept_local']);
        $this->assertSame(1, $a->currentVersion()); // preview wrote nothing
        $a->apply($set);
        $this->assertEqualsWithDelta((float) $tax->rate + 1, (float) $tax->fresh()->rate, 0.0001);
        $this->assertSame($localEnabled, (bool) $rule->fresh()->enabled); // local override kept
        $this->signed('rules/apply', ['ruleset' => ['format' => 'nope']])->assertStatus(422);
    }
}
