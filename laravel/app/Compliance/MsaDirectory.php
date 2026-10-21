<?php

namespace App\Compliance;

use App\Exceptions\WorkflowException;
use App\Models\MsaDirectoryEntry;
use App\Models\MsaDirectoryImport;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * State MSA "tobacco product manufacturer directory" lists (brand families of compliant manufacturers, cigarettes + RYO).
 * Each CSV import is the complete list for a state from its effective date: the previous list ends the day before.
 * CSV columns (header row required): manufacturer, brand_family, product_type (cigarette|ryo|both), [effective_from], [effective_to]
 */
class MsaDirectory
{
    public function import(string $state, string $csv, string $effectiveOn, ?User $by = null, ?string $filename = null, ?string $source = null): MsaDirectoryImport
    {
        $state = strtoupper(trim($state));
        if (! preg_match('/^[A-Z]{2}$/', $state)) {
            throw new WorkflowException('State must be a 2-letter code.');
        }
        $eff = CarbonImmutable::parse($effectiveOn)->toDateString();
        $rows = $this->parse($csv);
        if (! $rows) {
            throw new WorkflowException('No directory rows found. Expected columns: manufacturer, brand_family, product_type.');
        }

        return DB::transaction(function () use ($state, $eff, $rows, $by, $filename, $source) {
            MsaDirectoryEntry::where('state', $state)->whereDate('effective_from', '>=', $eff)->delete(); // replaced lists not yet in force
            MsaDirectoryEntry::where('state', $state)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $eff))
                ->update(['effective_to' => CarbonImmutable::parse($eff)->subDay()->toDateString()]);
            $imp = MsaDirectoryImport::create(['state' => $state, 'effective_on' => $eff, 'filename' => $filename ? mb_substr($filename, 0, 190) : null,
                'source' => $source ? mb_substr($source, 0, 250) : null, 'rows' => 0, 'user_id' => $by?->id]);
            $n = 0;
            foreach ($rows as $r) {
                foreach ($r['types'] as $type) {
                    MsaDirectoryEntry::create(['msa_directory_import_id' => $imp->id, 'state' => $state, 'manufacturer' => $r['manufacturer'],
                        'brand_family' => $r['brand'], 'brand_key' => MsaDirectoryEntry::key($r['brand']), 'product_type' => $type,
                        'effective_from' => $r['from'] && $r['from'] > $eff ? $r['from'] : $eff, 'effective_to' => $r['to']]);
                    $n++;
                }
            }
            $imp->update(['rows' => $n]);

            return $imp;
        });
    }

    /** @return array<int, array{manufacturer: string, brand: string, types: string[], from: ?string, to: ?string}> */
    public function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $csv), fn ($l) => trim($l) !== ''));
        if (count($lines) < 2) {
            return [];
        }
        $head = array_map(fn ($h) => strtolower(preg_replace('/[^a-z_]/i', '', str_replace(' ', '_', trim($h)))), str_getcsv(array_shift($lines)));
        $col = fn (array $names) => collect($names)->map(fn ($n) => array_search($n, $head, true))->first(fn ($i) => $i !== false);
        $iM = $col(['manufacturer', 'manufacturer_name', 'tobacco_product_manufacturer']);
        $iB = $col(['brand_family', 'brand', 'brand_name', 'brands']);
        $iT = $col(['product_type', 'type', 'product']);
        $iF = $col(['effective_from', 'from', 'listed_on']);
        $iTo = $col(['effective_to', 'to', 'removed_on']);
        if ($iB === null) {
            throw new WorkflowException('CSV needs a brand_family column.');
        }
        $out = [];
        foreach ($lines as $no => $line) {
            $c = str_getcsv($line);
            $brand = trim($c[$iB] ?? '');
            if ($brand === '') {
                continue;
            }
            $t = preg_replace('/[^a-z]/', '', strtolower($iT !== null ? ($c[$iT] ?? '') : ''));
            $types = match (true) {
                in_array($t, ['', 'both', 'cigaretteryo', 'cigarettesryo', 'cigaretteandryo', 'cigarettesandryo'], true) => ['cigarette', 'ryo'],
                in_array($t, ['ryo', 'rollyourown', 'rollyourowntobacco'], true) => ['ryo'],
                in_array($t, ['cig', 'cigarette', 'cigarettes'], true) => ['cigarette'],
                default => throw new WorkflowException('Row '.($no + 2).": unknown product_type '$t' (use cigarette, ryo or both)."),
            };
            $d = function ($i) use ($c, $no) {
                $v = $i !== null ? trim($c[$i] ?? '') : '';
                if ($v === '') {
                    return null;
                }
                try {
                    return CarbonImmutable::parse($v)->toDateString();
                } catch (\Throwable) {
                    throw new WorkflowException('Row '.($no + 2).": bad date '$v'.");
                }
            };
            $out[] = ['manufacturer' => mb_substr(trim($iM !== null ? ($c[$iM] ?? '') : '') ?: 'Unknown', 0, 190), 'brand' => mb_substr($brand, 0, 190),
                'types' => $types, 'from' => $d($iF), 'to' => $d($iTo)];
        }

        return $out;
    }

    private static function activeQuery(string $state, string $date)
    {
        return MsaDirectoryEntry::where('state', strtoupper($state))->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }

    public static function loaded(string $state, string $date): bool
    {
        return self::activeQuery($state, $date)->exists();
    }

    /** Brand family used for matching: the product's MSA brand family, else its brand. */
    public static function brandOf(Product $p): string
    {
        return trim((string) ($p->msa_brand_family ?: $p->brand));
    }

    public static function listed(Product $p, string $state, string $date): bool
    {
        $type = PactCategory::directoryType($p);
        $key = MsaDirectoryEntry::key(self::brandOf($p));

        return $type && $key !== '' && self::activeQuery($state, $date)->where('product_type', $type)->where('brand_key', $key)->exists();
    }
}
