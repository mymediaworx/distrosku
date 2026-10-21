<?php

namespace App\RulesHub;

use App\Models\ComplianceRuleVersion;
use App\Models\RulesSync;
use App\Models\Setting;
use App\Models\TaxRate;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/** One-way pull of signed compliance rules + tax rates. Sends nothing but install_id and app_version. */
class RulesHubClient
{
    public function url(): ?string
    {
        return Setting::get('rules_hub_url', config('erp.rules_hub.url'));
    }

    public function publicKey(): ?string
    {
        return Setting::get('rules_hub_public_key', config('erp.rules_hub.public_key'));
    }

    public function currentVersion(): ?string
    {
        return Setting::get('rules_hub_version');
    }

    public function sync(string $trigger = 'schedule'): RulesSync
    {
        $url = $this->url();
        $log = fn (string $status, string $msg, array $extra = []) => RulesSync::create(['status' => $status, 'message' => mb_substr($msg, 0, 2000), 'url' => $url, 'trigger' => $trigger] + $extra);

        if (! $url || ! $this->publicKey()) {
            return $log('failed', 'Rules Hub URL or public key not configured.');
        }
        try {
            $res = Http::timeout(config('erp.rules_hub.timeout', 20))->accept('application/json')
                ->get($url, ['install_id' => Setting::installId(), 'app_version' => config('erp.version')]);
            if (! $res->successful()) {
                return $log('failed', 'HTTP '.$res->status().' from Rules Hub.');
            }
            $payload = RulesPackage::verify($res->body(), $this->publicKey());
            $version = (string) $payload['version'];
            $current = $this->currentVersion();
            if ($current && version_compare($version, $current, '<=')) {
                return $log('unchanged', "Package version {$version} is not newer than installed {$current}.", ['version' => $version]);
            }

            [$rules, $rates] = DB::transaction(function () use ($payload, $version) {
                $n = 0;
                foreach ($payload['rules'] ?? [] as $r) {
                    ComplianceRuleVersion::updateOrCreate(['hub_id' => (string) $r['id']], [
                        'jurisdiction' => strtoupper($r['jurisdiction']),
                        'tax_class' => $r['tax_class'] ?? null, 'hemp_type' => $r['hemp_type'] ?? null,
                        'rule' => $r['rule'], 'banned' => (bool) ($r['banned'] ?? false),
                        'max_thc_mg' => $r['max_thc_mg'] ?? null, 'license_type' => $r['license_type'] ?? null,
                        'effective_from' => $r['effective_from'], 'effective_to' => $r['effective_to'] ?? null,
                        'source' => $r['source'] ?? 'Rules Hub', 'notes' => $r['notes'] ?? null,
                        'version' => 'hub-'.$version, 'hub_version' => $version,
                    ]);
                    $n++;
                }
                $m = 0;
                foreach ($payload['tax_rates'] ?? [] as $t) {
                    $attrs = Arr::only($t, ['tax_type', 'rate', 'basis', 'increment_oz', 'effective_from', 'effective_to', 'source', 'notes']) + [
                        'jurisdiction' => strtoupper($t['jurisdiction']), 'needs_verification' => (bool) ($t['needs_verification'] ?? false), 'hub_version' => $version,
                    ];
                    $row = TaxRate::where('hub_id', (string) $t['id'])->first()
                        // adopt a matching locally seeded row instead of colliding with the unique key
                        ?? TaxRate::whereNull('hub_id')->where('jurisdiction', $attrs['jurisdiction'])->where('tax_type', $attrs['tax_type'])
                            ->whereDate('effective_from', $attrs['effective_from'])->first()
                        ?? new TaxRate;
                    $row->fill($attrs + ['hub_id' => (string) $t['id']])->save();
                    $m++;
                }
                Setting::put('rules_hub_version', $version);
                Setting::put('rules_hub_synced_at', now()->toIso8601String());

                return [$n, $m];
            });

            return $log('ok', "Applied package {$version}.", ['version' => $version, 'rules_upserted' => $rules, 'rates_upserted' => $rates]);
        } catch (Throwable $e) {
            return $log('failed', $e->getMessage());
        }
    }
}
