<?php

namespace App\Dba;

use App\Models\ComplianceRuleVersion;
use App\Models\EligibilityRule;
use App\Models\LicenseType;
use App\Models\Setting;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * 0.20.8: global rule sets pushed from the instance manager. Format distrosku-rules/1:
 * {format, version, name, sets: {license_types:[...], eligibility_rules:[...], compliance_rules:[...], tax_rates:[...]}}
 * Every row carries `code` (stable across instances) and `overridable`. Upsert by code (then by natural key, adopting an
 * existing local row). Never deletes anything. Re-applying the same set changes nothing.
 */
class RulesApplier
{
    public const FORMAT = 'distrosku-rules/1';

    public static bool $applying = false;

    /** set => [model, natural-key columns, json columns] */
    public const SETS = [
        'license_types' => [LicenseType::class, ['code'], []],
        'eligibility_rules' => [EligibilityRule::class, ['key'], ['fda_statuses']],
        'compliance_rules' => [ComplianceRuleVersion::class, ['jurisdiction', 'rule', 'tax_class', 'hemp_type', 'license_type', 'effective_from'], []],
        'tax_rates' => [TaxRate::class, ['jurisdiction', 'tax_type', 'effective_from'], []],
    ];

    private const NEVER = ['id', 'version', 'created_at', 'updated_at', 'managed', 'managed_code', 'managed_version', 'managed_overridable', 'local_override', 'updated_by', 'hub_id', 'hub_version'];

    public function currentVersion(): ?int
    {
        $v = Setting::get('dba_rules_version');

        return $v === null ? null : (int) $v;
    }

    /** Dry run: what apply() would do. */
    public function preview(array $payload): array
    {
        return $this->run($payload, false);
    }

    public function apply(array $payload, ?string $by = null): array
    {
        $r = $this->run($payload, true);
        Setting::put('dba_rules_version', (string) (int) $payload['version']);
        Setting::put('dba_rules_applied_at', now()->toIso8601String());
        InstanceState::log('rules_applied', $by, ['version' => (int) $payload['version'], 'totals' => $r['totals']]);

        return $r;
    }

    private function run(array $payload, bool $write): array
    {
        if (($payload['format'] ?? null) !== self::FORMAT || ! isset($payload['version'], $payload['sets']) || ! is_array($payload['sets'])) {
            throw new InvalidArgumentException('Not a '.self::FORMAT.' rule set.');
        }
        $version = (int) $payload['version'];
        $out = ['version' => $version, 'installed_version' => $this->currentVersion(), 'sets' => [], 'totals' => ['create' => 0, 'update' => 0, 'same' => 0, 'kept_local' => 0]];
        $go = function () use ($payload, $version, $write, &$out) {
            foreach (self::SETS as $set => [$class, $natural, $json]) {
                $table = (new $class)->getTable();
                if (! Schema::hasTable($table)) {
                    continue;
                }
                $cols = array_flip(Schema::getColumnListing($table));
                foreach ($payload['sets'][$set] ?? [] as $row) {
                    $code = (string) ($row['code'] ?? '');
                    if ($code === '') {
                        throw new InvalidArgumentException("A {$set} row has no code.");
                    }
                    $attrs = array_diff_key(array_intersect_key((array) ($row['data'] ?? []), $cols), array_flip(self::NEVER));
                    /** @var Model|null $m */
                    $m = $class::query()->where('managed_code', $code)->first() ?? $this->byNatural($class, $natural, $attrs);
                    $action = 'create';
                    $changed = array_keys($attrs);
                    if ($m) {
                        $changed = $this->changed($m, $attrs, $json);
                        $flagsSame = $m->managed && $m->managed_code === $code && (bool) $m->managed_overridable === (bool) ($row['overridable'] ?? false);
                        if ($m->local_override && $m->managed_overridable) {
                            $action = 'kept_local';
                        } else {
                            $action = ! $changed && $flagsSame ? 'same' : 'update';
                        }
                    }
                    $out['sets'][$set][] = ['code' => $code, 'action' => $action, 'fields' => $action === 'update' ? $changed : []];
                    $out['totals'][$action]++;
                    if ($write && in_array($action, ['create', 'update'], true)) {
                        $m ??= new $class;
                        $m->forceFill($attrs + ['managed' => true, 'managed_code' => $code, 'managed_version' => $version,
                            'managed_overridable' => (bool) ($row['overridable'] ?? false), 'local_override' => false])->save();
                    }
                }
            }
        };
        if ($write) {
            self::$applying = true;
            try {
                DB::transaction($go);
            } finally {
                self::$applying = false;
            }
        } else {
            $go();
        }

        return $out;
    }

    private function byNatural(string $class, array $natural, array $attrs): ?Model
    {
        $q = $class::query()->whereNull('managed_code');
        foreach ($natural as $c) {
            $v = $attrs[$c] ?? null;
            if ($c === 'effective_from' && $v !== null) {
                $q->whereDate($c, substr((string) $v, 0, 10));
            } else {
                $v === null ? $q->whereNull($c) : $q->where($c, $v);
            }
        }

        return $q->first();
    }

    private function changed(Model $m, array $attrs, array $json): array
    {
        $diff = [];
        foreach ($attrs as $k => $v) {
            $cur = $m->getAttributes()[$k] ?? null;
            if (in_array($k, $json, true)) {
                $a = is_string($cur) ? json_decode($cur, true) : $cur;
                if ($a != $v) {
                    $diff[] = $k;
                }

                continue;
            }
            if (! self::same($cur, $v)) {
                $diff[] = $k;
            }
        }

        return $diff;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === null && $b === null;
        }
        if (is_bool($b) || is_bool($a)) {
            return (bool) $a === (bool) $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a == (float) $b;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $a) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $b)) {
            return substr((string) $a, 0, 10) === $b;
        }

        return (string) $a === (string) $b;
    }

    /** Export this instance's global lists as a rule set (used to seed the manager's v1). */
    public function export(int $version = 1, string $name = 'Imported from ERP'): array
    {
        $sets = [];
        foreach (self::SETS as $set => [$class, $natural, $json]) {
            $q = $class::query()->orderBy('id');
            if ($set === 'eligibility_rules') {
                $q->whereNotNull('key'); // system rules only; custom local rules stay local
            }
            foreach ($q->get() as $m) {
                $a = array_diff_key($m->getAttributes(), array_flip(self::NEVER));
                foreach ($json as $j) {
                    $a[$j] = is_string($a[$j] ?? null) ? json_decode($a[$j], true) : ($a[$j] ?? null);
                }
                foreach ($a as $k => $v) {
                    if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2} 00:00:00$/', $v)) {
                        $a[$k] = substr($v, 0, 10);
                    }
                }
                $code = $m->managed_code ?: self::codeFor($set, $a);
                $sets[$set][] = ['code' => $code, 'overridable' => $m->managed_code ? (bool) $m->managed_overridable : $set === 'eligibility_rules', 'data' => $a];
            }
        }

        return ['format' => self::FORMAT, 'version' => $version, 'name' => $name, 'exported_from' => config('erp.version'), 'sets' => $sets];
    }

    public static function codeFor(string $set, array $a): string
    {
        $d = fn ($v) => $v === null ? '-' : substr((string) $v, 0, 10);

        return match ($set) {
            'license_types' => 'lt:'.$a['code'],
            'eligibility_rules' => 'el:'.$a['key'],
            'tax_rates' => 'tx:'.strtoupper($a['jurisdiction']).':'.$a['tax_type'].':'.$d($a['effective_from']),
            'compliance_rules' => 'cr:'.strtoupper($a['jurisdiction']).':'.$a['rule'].':'.($a['tax_class'] ?? '-').':'.($a['hemp_type'] ?? '-').':'.($a['license_type'] ?? '-').':'.$d($a['effective_from']),
        };
    }
}
