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
    Schedule::command('marketing:invoice-events')->dailyAt('07:00')->withoutOverlapping(); // 0.19 (no-op when BrandWorx Marketing is off)
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
