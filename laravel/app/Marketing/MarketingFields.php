<?php

namespace App\Marketing;

use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * 0.19.2: ERP values -> BrandWorx Marketing contact custom fields. Fields are mapped by field key (or id) held in settings,
 * defaulting to the keys below, and resolved to ids through the API at runtime (cached 1 hour). No account ids are hard-coded.
 * Only text-like fields are written; anything else (e.g. a file-upload field) is skipped with a note.
 */
class MarketingFields
{
    public const DEFAULTS = [
        'account' => 'contact.vega_account_number',
        'state_tax' => 'contact.state_tax_id_number',
        'resale' => 'contact.resale_sales_tax_certificate',
    ];

    public const LABELS = ['account' => 'Vega Account Number', 'state_tax' => 'State Tax ID', 'resale' => 'Resale certificate'];

    public const WRITABLE = ['TEXT', 'LARGE_TEXT', 'NUMERICAL', 'PHONE', 'MONETORY', 'EMAIL'];

    public function __construct(private GhlClient $client) {}

    public static function mapping(): array
    {
        $m = [];
        foreach (self::DEFAULTS as $k => $d) {
            $v = trim((string) Setting::get("mkt_cf_{$k}", $d));
            $m[$k] = $v === '-' ? '' : $v;
        }

        return $m; // '-' in settings = do not send; blank = default key
    }

    public static function values(Customer $c): array
    {
        $resale = $c->licenses()->where('type', 'resale')->orderByRaw('expires_at is null')->orderByDesc('expires_at')->orderByDesc('id')->value('number');

        return ['account' => $c->code, 'state_tax' => $c->state_tax_id, 'resale' => $resale];
    }

    /** Field definitions of the location keyed by fieldKey and id. */
    public function definitions(): array
    {
        $loc = $this->client->locationId();

        return Cache::remember("mkt_cf_defs:{$loc}", 3600, function () use ($loc) {
            $r = $this->client->get("/locations/{$loc}/customFields", ['model' => 'contact']);
            if (! $r->successful()) {
                throw new \RuntimeException("Custom field lookup HTTP {$r->status()}", $r->status());
            }
            $out = [];
            foreach ((array) $r->json('customFields', []) as $f) {
                $d = ['id' => $f['id'] ?? null, 'type' => strtoupper((string) ($f['dataType'] ?? '')), 'name' => $f['name'] ?? ''];
                if ($d['id']) {
                    $out[$d['id']] = $d;
                    if (! empty($f['fieldKey'])) {
                        $out[$f['fieldKey']] = $d;
                    }
                }
            }

            return $out;
        });
    }

    /** @return array{0: array, 1: array} [customFields payload, notes] — empty ERP values are never sent (no blanking). */
    public function payload(Customer $c): array
    {
        $values = self::values($c);
        $map = self::mapping();
        $fields = [];
        $notes = [];
        $defs = null;
        foreach ($map as $k => $ref) {
            $v = $values[$k] ?? null;
            if ($ref === '' || $v === null || $v === '') {
                continue;
            }
            $defs ??= $this->definitions();
            $d = $defs[$ref] ?? null;
            if (! $d) {
                $notes[] = self::LABELS[$k].": field '{$ref}' not found";

                continue;
            }
            if (! in_array($d['type'], self::WRITABLE, true)) {
                $notes[] = self::LABELS[$k].": field '{$d['name']}' is {$d['type']}, not text; skipped";

                continue;
            }
            $fields[] = ['id' => $d['id'], 'field_value' => (string) $v];
        }

        return [$fields, $notes];
    }
}
