<?php

namespace App\Compliance;

use App\Audit\Audit;
use App\Imports\SheetReader;
use App\Models\Product;
use App\Models\StateRegistryEntry;
use App\Models\User;
use App\Models\VapeDirectoryListing;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * 0.18.1: Kentucky CHFS "Hemp-derived Cannabinoid Registered Products" registry (public REDCap report).
 * The report has no CSV export for the public, no SKU/UPC and no dates: columns are Record ID, Brand, Full product name,
 * Adult-use (Yes/No), Type of product (Beverage, Gummy, Vape, Other Edible, Topical, Other), Other type, Final label (PDF).
 * Import = "Fetch now" (reads the public report) or upload a CSV/XLSX with those columns.
 * Matching (THC vapes only, see Product::isThcVape): normalised "brand + product name" or product name equal to the
 * registry's, or a confirmed manual link. Fuzzy hits are only suggestions; nothing is listed on a guess.
 */
class KyHempRegistry
{
    public const URL = 'https://redcap.chfs.ky.gov/surveys/?__report=LXDTXPJH9E4RAY8R';

    public const REGISTRY = 'ky_hemp';

    public const STATE = 'KY';

    /** Registry column variable names (REDCap) and the CSV header aliases we accept. */
    public const COLUMNS = [
        'record_id' => ['record_id', 'record id', 'id', 'record'],
        'brand' => ['brand_of_product', 'brand of product', 'brand of product:', 'brand'],
        'product_name' => ['full_name_of_the_product', 'full name of the product', 'full name of the product:', 'product name', 'product', 'name'],
        'adult_use' => ['adult_use', 'adult use', 'adult-use'],
        'product_type' => ['type_of_product', 'type of product', 'type of product:', 'type'],
        'other_type' => ['other_type_of_product', 'other type of product', 'other type of product:', 'other type'],
    ];

    public function __construct(private ?Client $http = null) {}

    /** @return list<array<string, string>> rows read from the public report */
    public function fetch(): array
    {
        $jar = new CookieJar;
        $http = $this->http ?? new Client(['timeout' => 60, 'connect_timeout' => 15, 'headers' => ['User-Agent' => 'DistroSKU-ERP registry check']]);
        $page = (string) $http->get(self::URL, ['cookies' => $jar])->getBody();
        if (! preg_match('/var\s+report_id\s*=\s*(\d+)/', $page, $m)) {
            throw new RuntimeException('The KY registry page did not look like a REDCap report (no report id). Download it and upload the file instead.');
        }
        parse_str((string) parse_url(self::URL, PHP_URL_QUERY), $q);
        $rows = [];
        for ($pg = 1; $pg <= 50; $pg++) {
            $html = (string) $http->post('https://redcap.chfs.ky.gov/surveys/index.php?pagenum='.$pg.'&__report='.urlencode($q['__report'] ?? ''), [
                'cookies' => $jar, 'form_params' => ['report_id' => $m[1]], 'headers' => ['X-Requested-With' => 'XMLHttpRequest'],
            ])->getBody();
            [$got, $total] = self::parseHtml($html);
            $rows = array_merge($rows, $got);
            if (! $got || $total === null || count($rows) >= $total) {
                break;
            }
        }
        if (! $rows) {
            throw new RuntimeException('The KY registry report returned no rows.');
        }

        return $rows;
    }

    /** @return array{0: list<array<string,string>>, 1: ?int} rows + "Number of results returned" */
    public static function parseHtml(string $html): array
    {
        $total = preg_match('/Number of results returned:\s*<b[^>]*>\s*([\d,]+)/i', $html, $t) ? (int) str_replace(',', '', $t[1]) : null;
        if (! preg_match('/<table[^>]*id=.report_table.[^>]*>(.*?)<\/table>/is', $html, $tb)) {
            return [[], $total];
        }
        preg_match_all('/<th[^>]*>(.*?)<\/th>/is', $tb[1], $ths);
        $head = array_map(function ($h) {
            if (preg_match('/class="rpthdr">(.*?)<\/div>/is', $h, $v)) {
                return strtolower(trim(strip_tags($v[1])));
            }

            return strtolower(trim(html_entity_decode(strip_tags($h))));
        }, $ths[1]);
        $map = self::mapHeader($head);
        $rows = [];
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $tb[1], $trs);
        foreach ($trs[1] as $tr) {
            if (! preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $tr, $tds)) {
                continue;
            }
            $cells = array_map(fn ($c) => trim(html_entity_decode(strip_tags(preg_replace('/<span class="ch">.*?<\/span>/is', '', $c)), ENT_QUOTES | ENT_HTML5)), $tds[1]);
            $rows[] = self::rowFrom($cells, $map);
        }

        return [array_values(array_filter($rows)), $total];
    }

    /** @return list<array<string,string>> rows from an uploaded CSV / XLSX (first row = headers) */
    public static function readFile(string $path, ?string $name = null): array
    {
        $map = null;
        $rows = [];
        foreach (SheetReader::rows($path, $name) as $cells) {
            if ($map === null) {
                $map = self::mapHeader(array_map(fn ($h) => strtolower(trim((string) $h)), $cells));
                if (! isset($map['product_name'])) {
                    throw new RuntimeException('The file needs a product name column ("Full name of the product" or "Product name"); "Brand", "Type of product" and "Record ID" are used when present.');
                }

                continue;
            }
            if ($r = self::rowFrom($cells, $map)) {
                $rows[] = $r;
            }
        }

        return $rows;
    }

    private static function mapHeader(array $head): array
    {
        $map = [];
        foreach (self::COLUMNS as $field => $aliases) {
            foreach ($head as $i => $h) {
                $h = trim(preg_replace('/\s+/', ' ', str_replace("\u{200B}", '', $h)));
                if (in_array($h, $aliases, true) || in_array(rtrim($h, ':'), $aliases, true)) {
                    $map[$field] = $i;
                    break;
                }
            }
        }

        return $map;
    }

    private static function rowFrom(array $cells, array $map): ?array
    {
        $r = [];
        foreach ($map as $f => $i) {
            $r[$f] = mb_substr(trim((string) ($cells[$i] ?? '')), 0, $f === 'product_name' ? 250 : 190);
        }
        if (($r['product_name'] ?? '') === '') {
            return null;
        }
        $r['product_type'] = preg_replace('/\s*\(\d+\)$/', '', $r['product_type'] ?? '');
        $r['adult_use'] = preg_replace('/\s*\(\d+\)$/', '', $r['adult_use'] ?? '');

        return $r;
    }

    /**
     * Load a full registry snapshot, mark entries that disappeared as removed (only when the snapshot looks complete),
     * then match THC vapes. @return array<string, int>
     */
    public function import(array $rows, string $source, ?User $by = null, ?Carbon $on = null): array
    {
        $on ??= Carbon::today();
        $d = $on->toDateString();
        $stats = ['rows' => count($rows), 'new' => 0, 'updated' => 0, 'removed' => 0, 'vapes' => 0];
        $seen = [];
        foreach ($rows as $r) {
            $key = StateRegistryEntry::key($r['brand'] ?? '', $r['product_name']);
            $rid = trim((string) ($r['record_id'] ?? '')) ?: 'k:'.substr(sha1($key), 0, 20);
            $seen[] = $rid;
            $vals = ['state' => self::STATE, 'brand' => ($r['brand'] ?? '') ?: null, 'product_name' => $r['product_name'],
                'product_type' => ($r['product_type'] ?? '') ?: null, 'other_type' => ($r['other_type'] ?? '') ?: null,
                'adult_use' => mb_substr((string) ($r['adult_use'] ?? ''), 0, 10) ?: null,
                'match_key' => mb_substr($key, 0, 250), 'name_key' => mb_substr(StateRegistryEntry::norm($r['product_name']), 0, 250),
                'last_seen_on' => $d, 'removed_on' => null];
            $e = StateRegistryEntry::where('registry', self::REGISTRY)->where('record_id', $rid)->first();
            if ($e) {
                $e->update($vals);
                $stats['updated']++;
            } else {
                StateRegistryEntry::create($vals + ['registry' => self::REGISTRY, 'record_id' => $rid, 'first_seen_on' => $d]);
                $stats['new']++;
            }
            $stats['vapes'] += strcasecmp((string) ($r['product_type'] ?? ''), 'vape') === 0 ? 1 : 0;
        }
        $active = StateRegistryEntry::where('registry', self::REGISTRY)->whereNull('removed_on')->count();
        // A partial file / failed page must not de-list products: only treat missing rows as removed when the snapshot has
        // at least 80% of what is on file.
        if ($rows && count($seen) >= 0.8 * $active) {
            foreach (array_chunk(StateRegistryEntry::where('registry', self::REGISTRY)->whereNull('removed_on')->pluck('record_id')->diff($seen)->all(), 500) as $gone) {
                $stats['removed'] += StateRegistryEntry::where('registry', self::REGISTRY)->whereIn('record_id', $gone)->update(['removed_on' => $d]);
            }
        }
        $m = $this->match($by, $on);
        Audit::action('ky_registry_import', null, "KY hemp registry loaded from {$source}: {$stats['rows']} rows, {$stats['new']} new, {$stats['removed']} removed; {$m['listed']} SKU(s) listed, {$m['delisted']} de-listed",
            $stats + $m + ['source' => mb_substr($source, 0, 190)], [], $by?->id);

        return $stats + $m;
    }

    /** THC vape products that the KY rule looks at. @return Collection<int, Product> */
    public static function thcVapes(): Collection
    {
        return Product::query()->where('active', true)->notArchived()->orderBy('sku')->get()->filter(fn (Product $p) => $p->isThcVape())->values();
    }

    /** @return Collection<int, StateRegistryEntry> exact matches for the product (current entries) */
    public static function exactFor(Product $p): Collection
    {
        $keys = array_values(array_unique(array_filter([StateRegistryEntry::key($p->brand, $p->name), StateRegistryEntry::norm($p->name)])));
        if (! $keys) {
            return collect();
        }

        return StateRegistryEntry::where('registry', self::REGISTRY)->whereNull('removed_on')
            ->where(fn ($q) => $q->whereIn('match_key', $keys)->orWhereIn('name_key', $keys))
            ->get()->sortBy(fn ($e) => strcasecmp((string) $e->product_type, 'vape') === 0 ? 0 : 1)->values();
    }

    /** Closest registry vapes (similarity %), review-only. @return Collection<int, array{entry: StateRegistryEntry, score: float}> */
    public static function suggestions(Product $p, int $limit = 3, float $min = 60.0): Collection
    {
        $k = StateRegistryEntry::key($p->brand, $p->name);
        if ($k === '') {
            return collect();
        }

        return StateRegistryEntry::where('registry', self::REGISTRY)->whereNull('removed_on')->where('product_type', 'Vape')->get()
            ->map(function ($e) use ($k) {
                similar_text($k, (string) $e->match_key, $pct);

                return ['entry' => $e, 'score' => round($pct, 1)];
            })->filter(fn ($x) => $x['score'] >= $min)->sortByDesc('score')->take($limit)->values();
    }

    public static function currentListing(Product $p, ?string $date = null): ?VapeDirectoryListing
    {
        $d = Carbon::parse($date ?? Carbon::today())->toDateString();

        return VapeDirectoryListing::where('product_id', $p->id)->where('state', self::STATE)->whereDate('listed_from', '<=', $d)
            ->where(fn ($q) => $q->whereNull('listed_to')->orWhereDate('listed_to', '>=', $d))->latest('id')->first();
    }

    /** Auto-list exact matches; end automatic listings whose registry entry was removed. Manual listings are never touched. */
    public function match(?User $by = null, ?Carbon $on = null): array
    {
        $on ??= Carbon::today();
        $out = ['listed' => 0, 'delisted' => 0, 'unmatched' => 0];
        $url = self::URL;
        foreach (VapeDirectoryListing::where('state', self::STATE)->where('manual', false)->whereNull('listed_to')->whereNotNull('registry_entry_id')->with('registryEntry')->get() as $l) {
            if ($l->registryEntry?->removed_on) {
                $l->update(['listed_to' => $l->registryEntry->removed_on->copy()->subDay()->max($l->listed_from)->toDateString(), 'note' => 'Removed from the KY CHFS registry']);
                $out['delisted']++;
            }
        }
        foreach (self::thcVapes() as $p) {
            if (self::currentListing($p, $on->toDateString())) {
                continue;
            }
            $e = self::exactFor($p)->first();
            if (! $e) {
                $out['unmatched']++;

                continue;
            }
            VapeDirectoryListing::create(['state' => self::STATE, 'product_id' => $p->id, 'listed_from' => $e->first_seen_on->toDateString(),
                'listing_ref' => 'KY CHFS #'.$e->record_id, 'source' => mb_substr($url, 0, 250), 'registry_entry_id' => $e->id,
                'manual' => false, 'listed_by' => $by?->id, 'note' => 'Auto-matched: '.mb_substr($e->label(), 0, 220)]);
            $out['listed']++;
        }

        return $out;
    }

    /** Confirmed link from a reviewer (e.g. a suggestion), counts as manual. */
    public function link(Product $p, StateRegistryEntry $e, User $by, ?string $from = null): VapeDirectoryListing
    {
        $l = VapeDirectoryListing::create(['state' => self::STATE, 'product_id' => $p->id, 'listed_from' => Carbon::parse($from ?? Carbon::today())->toDateString(),
            'listing_ref' => 'KY CHFS #'.$e->record_id, 'source' => self::URL, 'registry_entry_id' => $e->id, 'manual' => true, 'listed_by' => $by->id,
            'note' => 'Linked by '.mb_substr($by->name, 0, 60).': '.mb_substr($e->label(), 0, 160)]);
        Audit::action('ky_registry_link', $p, "Linked {$p->sku} to KY registry {$e->label()}", ['listing_id' => $l->id]);

        return $l;
    }

    /** Manual "listed" toggle: on = listed from a date; off = listing ends the day before $date. */
    public function setManual(Product $p, bool $listed, string $date, User $by, ?string $note = null): void
    {
        $d = Carbon::parse($date)->toDateString();
        if ($listed) {
            VapeDirectoryListing::create(['state' => self::STATE, 'product_id' => $p->id, 'listed_from' => $d, 'manual' => true, 'listed_by' => $by->id,
                'listing_ref' => 'manual', 'source' => self::URL, 'note' => mb_substr(trim((string) $note) ?: 'Marked listed by '.$by->name, 0, 250)]);
        } else {
            foreach (VapeDirectoryListing::where('product_id', $p->id)->where('state', self::STATE)
                ->where(fn ($q) => $q->whereNull('listed_to')->orWhereDate('listed_to', '>=', $d))->get() as $l) {
                $end = Carbon::parse($d)->subDay();
                $end->lt($l->listed_from) ? $l->delete() : $l->update(['listed_to' => $end->toDateString(), 'note' => mb_substr(trim((string) $note) ?: 'Unlisted by '.$by->name, 0, 250)]);
            }
        }
        Audit::action($listed ? 'ky_registry_listed' : 'ky_registry_unlisted', $p, ($listed ? 'Marked listed' : 'Marked not listed')." on the KY hemp registry from {$d}".($note ? ": {$note}" : ''));
    }
}
