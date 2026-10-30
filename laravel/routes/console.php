<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use App\RulesHub\RulesHubClient;
use App\RulesHub\RulesPackage;
use Illuminate\Support\Facades\Schedule;

// 14.1: during an upgrade the new laravel/ folder can be live for a moment before .env is copied in; cron must not run then
// (it fell back to a missing database/database.sqlite at 20:15 on 0.14.0 install). Skip scheduling until .env (or cached config) exists.
if (is_file(base_path('.env')) || app()->configurationIsCached() || app()->runningUnitTests()) {
    // Shared hosting (SiteGround): no supervisor, so drain the database queue each minute via cron -> schedule:run.
    Schedule::command('queue:work --stop-when-empty --tries=3 --max-time=50')
        ->everyMinute()
        ->withoutOverlapping()
        ->runInBackground();

    // Rules Hub: nightly one-way pull of signed compliance rules + tax rates.
    Schedule::command('rules:sync')->dailyAt('02:30')->withoutOverlapping();
    // 0.17: vendor price files with a future effective date.
    Schedule::command('costs:apply')->dailyAt('00:05')->withoutOverlapping();
    Schedule::command('pos:expire-holds')->dailyAt('00:15')->withoutOverlapping(); // 0.19.3
    Schedule::command('marketing:reconcile')->dailyAt('02:45')->withoutOverlapping(); // 0.19.2: nightly, NEW (unlinked) customers only
    Schedule::command('warehouse:cycle-plan')->dailyAt('05:30')->withoutOverlapping(); // 0.20.17 daily/weekly cycle count tasks
    Schedule::command('po:inbound-digest')->dailyAt('06:30')->withoutOverlapping(); // 0.20.1 receiving-team morning digest
    Schedule::command('dunning:send')->dailyAt('07:00')->withoutOverlapping(); // 0.20.18 auto-email dunning (no-op while the setting is off)
    Schedule::command('marketing:invoice-events')->dailyAt('07:00')->withoutOverlapping(); // 0.19 (no-op when BrandWorx Marketing is off)

    // 0.20.8: suspended by the instance manager = pause every scheduled job and alert (queue included). Backups keep running.
    foreach (app(\Illuminate\Console\Scheduling\Schedule::class)->events() as $event) {
        $event->skip(fn () => \App\Dba\InstanceState::suspended());
    }
    Schedule::command('erp:backup --trigger=nightly')->dailyAt('01:30')->withoutOverlapping(); // 0.20.8 nightly backup + retention
    // #22: the scheduler's heartbeat for /health (UptimeRobot alerts when cron stops). Runs even while suspended: cron is still alive.
    Schedule::call(fn () => \App\Health\HealthCheck::beat())->everyMinute()->name('health-heartbeat');
}

Artisan::command('rules:sync', function () {
    $s = app(RulesHubClient::class)->sync('schedule');
    $this->line("{$s->status}: {$s->message}");

    return $s->status === 'failed' ? 1 : 0;
})->purpose('Pull and apply the signed Rules Hub package');

Artisan::command('rules:keygen {--out= : write the SECRET key to this file (chmod 600)}', function () {
    $kp = RulesPackage::keypair();
    if ($out = $this->option('out')) {
        file_put_contents($out, $kp['secret']);
        @chmod($out, 0600);
        $this->info("Secret key written to {$out}. Keep it OFF every install and out of git.");
    } else {
        $this->warn('Secret key (store safely, never on an install): '.$kp['secret']);
    }
    $this->line('Public key (RULES_HUB_PUBLIC_KEY / Settings): '.$kp['public']);
})->purpose('Generate an Ed25519 keypair for the Rules Hub');

Artisan::command('rules:build {input : unsigned payload JSON (format vega-rules/1)} {--sign= : path to base64 Ed25519 secret key} {--out=rules.json} {--key-id=hub-1}', function () {
    $payload = json_decode((string) file_get_contents($this->argument('input')), true);
    $keyPath = $this->option('sign');
    if (! $keyPath || ! is_readable($keyPath)) {
        $this->error('--sign=<secret key path> is required.');

        return 1;
    }
    $payload['issued_at'] ??= now()->toIso8601String();
    file_put_contents($this->option('out'), RulesPackage::sign($payload, (string) file_get_contents($keyPath), $this->option('key-id')));
    $this->info("Signed package v{$payload['version']} written to {$this->option('out')}. Upload it to any static host.");

    return 0;
})->purpose('Build + sign a Rules Hub package from a payload JSON');

Artisan::command('costs:apply', function () {
    $n = app(\App\Imports\ScheduledCostService::class)->applyDue();
    $this->line("Applied {$n} scheduled cost change(s).");
})->purpose('Apply vendor cost changes that have reached their effective date');

// 0.19: BrandWorx Marketing invoice due / overdue events (tags / inbound webhook). No-op unless the module is enabled.
Artisan::command('marketing:invoice-events {--days=3}', function () {
    $n = \App\Marketing\MarketingEvents::invoiceDates((int) $this->option('days'));
    $this->line("Queued {$n} invoice event(s).");
})->purpose('Queue BrandWorx Marketing invoice due/overdue events');

// 0.19.2: sync is one-way (ERP -> BrandWorx Marketing). Nightly: queue only active customers not yet linked to a contact.
// Edits to linked customers sync right away through the queue (MarketingEvents).
Artisan::command('marketing:reconcile', function () {
    if (! \App\Marketing\MarketingSettings::enabled()) {
        return;
    }
    $n = \App\Marketing\MarketingSync::queueMany(\App\Models\Customer::where('active', true)->whereNull('ghl_contact_id'));
    $this->line("Queued {$n} new customer(s).");
})->purpose('Queue customers not yet in BrandWorx Marketing');

// 0.19.3: remove POS held sales past their expiry (Setting pos_hold_days, default 7). Each removal is logged.
Artisan::command('pos:expire-holds', function () {
    $this->line('Expired '.\App\Models\PosHeldSale::purgeExpired().' held sale(s).');
})->purpose('Remove expired POS held sales');

Artisan::command('po:inbound-digest', function () {
    $n = \App\Purchasing\ReceivingAlerts::digest();
    $this->line("Inbound digest: {$n} notification(s) sent.");
})->purpose('0.20.1: morning digest of POs expected today/tomorrow and overdue, to each warehouse receiving team');

// 0.20.8: instance backup / restore (archives outside the web root; see config erp.dba.backup_path).
Artisan::command('erp:backup {--trigger=manual} {--list : list backups instead} {--verify : with --list, re-hash every archive}', function () {
    $b = app(\App\Dba\BackupService::class);
    if ($this->option('list')) {
        $this->table(['File', 'Size', 'Created (UTC)', 'Status'], array_map(fn ($r) => [$r['file'], number_format($r['size']), $r['created_at'], $r['status']], $b->list((bool) $this->option('verify'))));

        return 0;
    }
    $r = $b->create((string) $this->option('trigger'), 'cli');
    $this->info("Backup {$r['file']} ({$r['tables']} tables, {$r['rows']} rows) sha256 {$r['sha256']}");
    $this->line('Stored in '.$b->dir());

    return 0;
})->purpose('Back up the database, .env and storage/app into a checksummed archive (14 daily + 8 weekly kept)');

Artisan::command('erp:restore {backup : file name in this instance\'s backup folder, or a full path (e.g. another instance\'s backup)} {--with-env : also restore .env (same instance only)} {--force}', function () {
    $b = app(\App\Dba\BackupService::class);
    $path = $b->resolve($this->argument('backup'));
    $m = $b->verify($path);
    $this->info('Checksum OK. Backup of '.$m['instance'].' taken '.$m['created_at'].' on '.$m['erp_version'].'.');
    if (! $this->option('force') && ! $this->confirm('Replace ALL data in '.$b->instance().' with this backup? A safety backup is taken first.')) {
        return 1;
    }
    $r = $b->restore($path, (bool) $this->option('with-env'), true, 'cli');
    $this->info("Restored {$r['tables']} tables ({$r['rows']} rows) and {$r['files']} files. Safety backup: {$r['safety_backup']}");
    if ($r['version_warning']) {
        $this->warn($r['version_warning']);
    }

    return 0;
})->purpose('Restore a backup (verifies checksums; takes a safety backup first)');

Artisan::command('erp:rules-export {--out=}', function () {
    $json = json_encode(app(\App\Dba\RulesApplier::class)->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $this->option('out') ? file_put_contents($this->option('out'), $json) : $this->line($json);
})->purpose('Export license types, eligibility, compliance rules and tax rates as a distrosku-rules/1 rule set');

Artisan::command('warehouse:cycle-plan {--force : generate for today even if already run}', function () {
    $p = app(\App\Warehouse\CycleCountPlanner::class);
    $n = $this->option('force') ? $p->generateAll() : $p->ensureCurrent();
    $this->info("Cycle count plan: {$n} task(s) created.");
})->purpose('Generate the daily/weekly cycle count tasks (0.20.17)');

// 0.20.18: automatic dunning email. Does nothing unless Company Settings > Email > Auto-email dunning is ON and a real mail server is set up.
Artisan::command('dunning:send {--as-of= : date (default today)}', function () {
    $r = app(\App\Accounting\DunningMailer::class)->runDaily($this->option('as-of') ?: null);
    if ($r['skipped']) {
        $this->line($r['skipped']);

        return 0;
    }
    $this->info("Dunning email: {$r['created']} new notice(s); {$r['sent']} sent, {$r['failed']} failed (retry next run), {$r['print']} on the print list, {$r['duplicate']} duplicate stage(s) skipped, {$r['not_due']} no longer due.");

    return 0;
})->purpose('0.20.18: make today\'s dunning notices and email them (statement PDF attached)');

// 0.20.21: read-only report on the regulated-product flag (tax_class) that drives the walk-in / cash rule.
Artisan::command('erp:regulated-report {--limit=50 : max suspect products to list} {--like=* : also list products whose name/SKU/brand contains this text, with their tax class (e.g. --like="white owl")}', function () {
    $regulated = \App\Enums\TaxClass::regulatedValues();
    $this->info('Regulated = product tax class other than general / beverage (tobacco, vapor, hemp/CBD, kratom).');
    $rows = [];
    foreach (\App\Enums\TaxClass::cases() as $tc) {
        $rows[] = [$tc->value, \App\Models\Product::where('tax_class', $tc->value)->count(), $tc->isRegulated() ? 'REGULATED' : '-'];
    }
    $this->table(['tax_class', 'products', 'walk-in rule'], $rows);
    $this->line('Regulated products (blocked for walk-in / cash): '.\App\Models\Product::whereIn('tax_class', $regulated)->count().' of '.\App\Models\Product::count());
    $walk = \App\Models\Customer::query()->where(fn ($q) => $q->where('walk_in', true)->orWhereRaw('UPPER(code) = ?', ['CASH']))->get(['code', 'name']);
    $this->line('Walk-in / cash customers ('.$walk->count().'): '.$walk->map(fn ($c) => "{$c->code} {$c->name}")->implode('; '));
    $re = 'tobacco|cigar|cigarette|vape|vapor|cbd|hemp|kratom|white owl|swisher|backwoods|dutch master|black & mild|zig.?zag|newport|marlboro|camel|copenhagen|grizzly|skoal|juul|elf bar|delta|thc|snus|nicotine';
    $suspects = \App\Models\Product::query()->whereNotIn('tax_class', $regulated)->get()->filter(
        fn ($p) => preg_match("/($re)/i", implode(' ', [$p->category, $p->department?->name, $p->name]))
    );
    $this->line('Possible misses (tax class general/beverage but tobacco/CBD/kratom wording in category, department or name): '.$suspects->count());
    foreach ($suspects->take((int) $this->option('limit')) as $p) {
        $this->line("  {$p->sku}  {$p->name}  [tax_class={$p->tax_class->value}, category={$p->category}, dept={$p->department?->name}]");
    }
    foreach ((array) $this->option('like') as $like) {
        $hits = \App\Models\Product::query()->where(fn ($q) => $q->where('name', 'like', "%{$like}%")->orWhere('sku', 'like', "%{$like}%")->orWhere('brand', 'like', "%{$like}%"))->orderBy('name')->get();
        $this->line("Products matching \"{$like}\": {$hits->count()}");
        foreach ($hits->take((int) $this->option('limit')) as $p) {
            $this->line("  {$p->sku}  {$p->name}  tax_class={$p->tax_class?->value}  ".($p->isRegulated() ? 'REGULATED' : 'NOT regulated (sellable to walk-in!)'));
        }
    }
    $this->comment('Nothing was changed. Fix a miss by setting its Tax class on the product.');
})->purpose('Report which products count as regulated (walk-in / cash rule) and list likely misses');
