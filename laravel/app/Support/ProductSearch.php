<?php

namespace App\Support;

use App\Models\Product;
use App\Models\VendorItem;
use App\Warehouse\ProductLookup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * 0.20.13: product lookup for admin forms (special prices, etc.).
 * Matches SKU, name, barcode / UPC, and vendor item number.
 */
final class ProductSearch
{
    /** Options for a Filament searchable Select: id => "SKU Name (list $x)". */
    public static function options(string $search, int $limit = 30): array
    {
        return self::query($search)->with('units')->limit($limit)->get()
            ->mapWithKeys(fn (Product $p) => [$p->id => self::label($p)])
            ->all();
    }

    public static function label(Product $p): string
    {
        return "{$p->sku} {$p->name} (list \${$p->price} / ".self::unitLabel($p).')';
    }

    /** What one priced unit is, plus the bigger units it packs into, so look-alike products can be told apart: "Pack · CS ×12 · CTN ×10". */
    public static function unitLabel(Product $p): string
    {
        $parts = [trim((string) $p->base_unit_name) !== '' ? (string) $p->base_unit_name : 'Each'];
        foreach ($p->units as $u) {
            $f = rtrim(rtrim(number_format((float) $u->factor, 4, '.', ''), '0'), '.');
            $parts[] = trim(($u->code ?: $u->name).' ×'.$f);
        }

        return implode(' · ', $parts);
    }

    public static function basePriceLabel(?Product $p): string
    {
        if (! $p) {
            return 'Select a product to see its list price.';
        }

        return '$'.number_format((float) $p->price, 2).' (list)';
    }

    /** Punctuation that is ignored when comparing text (& . / - ' , : ; _ ( ) # * ! +). Same set is stripped from typed words. */
    private const STRIP = ['&', '.', '/', '-', "''", ',', ':', ';', '_', '(', ')', '#', '*', '!', '+', '’'];

    private const MAX_WORDS = 8;

    /** @var array<string, list<list<string>>>|null */
    private static ?array $synonymMap = null;

    /** Lower-case, letters and digits only: "J-6328" -> "j6328", "B&M" -> "bm". */
    public static function canon(string $word): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($word));
    }

    /** @return list<string> */
    public static function words(string $text): array
    {
        $out = [];
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $w) {
            $c = self::canon($w);
            if ($c !== '') {
                $out[] = $c;
            }
        }

        return array_slice($out, 0, self::MAX_WORDS * 2);
    }

    /** config('product_search.synonyms') keyed by canonical words ("black n mild"), each with its alternative expansions. */
    private static function synonyms(): array
    {
        $cfg = (array) config('product_search.synonyms', []);
        $sig = md5(json_encode($cfg));
        if (self::$synonymMap !== null && (self::$synonymMap['__sig'] ?? null) === $sig) {
            return self::$synonymMap;
        }
        $map = ['__sig' => $sig];
        foreach ($cfg as $key => $vals) {
            $k = implode(' ', self::words((string) $key));
            if ($k === '') {
                continue;
            }
            foreach ((array) $vals as $v) {
                $w = self::words((string) $v);
                if ($w !== []) {
                    $map[$k][] = $w;
                }
            }
        }

        return self::$synonymMap = $map;
    }

    /**
     * Split a typed query into groups. Every group must match (AND); inside a group any alternative may match (OR).
     * Group = ['words' => typed words, 'alts' => list of word lists (synonym expansions), 'syn' => bool].
     *
     * @return list<array{words: list<string>, alts: list<list<string>>, syn: bool}>
     */
    public static function parse(string $query): array
    {
        $words = self::words($query);
        $map = self::synonyms();
        $maxKey = 1;
        foreach (array_keys($map) as $k) {
            if ($k !== '__sig') {
                $maxKey = max($maxKey, substr_count((string) $k, ' ') + 1);
            }
        }
        $groups = [];
        $n = count($words);
        for ($i = 0; $i < $n && count($groups) < self::MAX_WORDS;) {
            $hit = null;
            for ($len = min($maxKey, $n - $i); $len >= 1; $len--) {
                $key = implode(' ', array_slice($words, $i, $len));
                if ($key !== '__sig' && isset($map[$key])) {
                    $hit = [$len, $map[$key], array_slice($words, $i, $len)];
                    break;
                }
            }
            if ($hit) {
                $groups[] = ['words' => $hit[2], 'alts' => $hit[1], 'syn' => true];
                $i += $hit[0];
            } else {
                $groups[] = ['words' => [$words[$i]], 'alts' => [], 'syn' => false];
                $i++;
            }
        }

        return $groups;
    }

    /** SQL concatenation that works on MariaDB/MySQL and SQLite. @param list<string> $parts */
    private static function cat(array $parts): string
    {
        $driver = Product::query()->getConnection()->getDriverName();

        return in_array($driver, ['sqlite', 'pgsql'], true) ? '('.implode(" || ' ' || ", $parts).')' : 'CONCAT_WS(\' \', '.implode(', ', $parts).')';
    }

    private static function norm(string $expr): string
    {
        $e = "LOWER({$expr})";
        foreach (self::STRIP as $ch) {
            $e = "REPLACE({$e}, '{$ch}', '')";
        }

        return $e;
    }

    /** Normalised text of everything on the product row that people type: name, brand, SKU, category. */
    private static function hay(): string
    {
        return self::norm(self::cat(["COALESCE(products.name, '')", "COALESCE(products.brand, '')", "COALESCE(products.sku, '')", "COALESCE(products.category, '')"]));
    }

    private static function nameNorm(): string
    {
        // Collapse the gap left by a removed "&" so "Black & Mild" reads "black mild".
        return 'REPLACE(REPLACE('.self::norm("COALESCE(products.name, '')").", '  ', ' '), '  ', ' ')";
    }

    /** SQL + bindings: does this one word match anywhere on the product (name/brand/SKU/category, UPC, barcodes, vendor item #, department)? */
    private static function wordSql(string $t, bool $whole): array
    {
        $like = '%'.$t.'%';
        $hay = self::hay();
        if ($whole) {
            $driver = Product::query()->getConnection()->getDriverName();
            $padded = in_array($driver, ['sqlite', 'pgsql'], true) ? "(' ' || {$hay} || ' ')" : "CONCAT(' ', {$hay}, ' ')";
            $sql = ["{$padded} LIKE ?"];
            $bind = ['% '.$t.' %'];
        } else {
            $sql = ["{$hay} LIKE ?"];
            $bind = [$like];
        }
        if (mb_strlen($t) >= 2) {
            $sql[] = 'products.id IN (SELECT product_id FROM product_barcodes WHERE '.self::norm('code').' LIKE ?)';
            $sql[] = 'products.id IN (SELECT product_id FROM vendor_items WHERE '.self::norm('vendor_item_no').' LIKE ?)';
            $sql[] = 'products.department_id IN (SELECT id FROM departments WHERE '.self::norm('name').' LIKE ?)';
            array_push($bind, $like, $like, $like);
        }
        if (preg_match('/\d/', $t)) {
            $vars = ProductLookup::variants($t);
            $in = implode(', ', array_fill(0, count($vars), '?'));
            $sql[] = "products.upc IN ({$in})";
            $sql[] = "products.msa_upc IN ({$in})";
            array_push($bind, ...$vars, ...$vars);
            if (ctype_digit($t) && strlen($t) >= 4) {
                $sql[] = 'products.upc LIKE ?';
                $sql[] = 'products.msa_upc LIKE ?';
                array_push($bind, $like, $like);
            }
        }

        return ['('.implode(' OR ', $sql).')', $bind];
    }

    /** SQL + bindings for one group: typed word(s) as whole words (when it is an abbreviation), OR any expansion. */
    private static function groupSql(array $g): array
    {
        $alts = [];
        $bind = [];
        $variants = [$g['words']];
        foreach ($g['alts'] as $a) {
            $variants[] = $a;
        }
        foreach ($variants as $vi => $ws) {
            $parts = [];
            foreach ($ws as $w) {
                [$s, $b] = self::wordSql($w, $g['syn'] && $vi === 0);
                $parts[] = $s;
                array_push($bind, ...$b);
            }
            $alts[] = '('.implode(' AND ', $parts).')';
        }

        return ['('.implode(' OR ', $alts).')', $bind];
    }

    /**
     * Narrow any products query to the typed words: every word must match somewhere (AND), in any order, ignoring case and
     * punctuation. Does not filter active/archived (the caller decides) and does not order.
     */
    public static function matching(Builder $q, string $search): Builder
    {
        $term = trim($search);
        if ($term === '') {
            return $q;
        }
        $groups = self::parse($term);
        if ($groups === []) { // only punctuation typed, e.g. "&"
            return $q->where('products.name', 'like', '%'.$term.'%');
        }
        foreach ($groups as $g) {
            [$sql, $bind] = self::groupSql($g);
            $q->whereRaw($sql, $bind);
        }

        return $q;
    }

    /**
     * Active, not-archived products matching the typed words, best first:
     * exact SKU / UPC / barcode / vendor item # hit, then name starts with the typed text, then more typed words in the name,
     * then in stock, then name.
     */
    public static function query(string $search): Builder
    {
        $term = trim($search);
        $q = Product::query()->where('products.active', true)->notArchived();
        if ($term === '') {
            return $q->orderBy('products.name')->orderBy('products.id');
        }
        self::matching($q, $term);

        return self::rank($q, $term)->orderBy('products.name')->orderBy('products.id');
    }

    /** Add the best-match-first ordering for a typed query (used by query() and the Filament global search). */
    public static function rank(Builder $q, string $search): Builder
    {
        $term = trim($search);
        if ($term === '') {
            return $q;
        }
        $groups = self::parse($term);
        $words = self::words($term);
        $first = $groups[0]['words'][0] ?? ($words[0] ?? '');
        $phrase = implode(' ', $words);
        $vars = ProductLookup::variants($term);
        $in = implode(', ', array_fill(0, count($vars), '?'));
        $lower = mb_strtolower($term);
        $name = self::nameNorm();

        $q->orderByRaw(
            "CASE WHEN LOWER(products.sku) = ? OR products.upc IN ({$in}) OR products.msa_upc IN ({$in})"
            ." OR EXISTS (SELECT 1 FROM product_barcodes pb WHERE pb.product_id = products.id AND pb.code IN ({$in}))"
            .' OR EXISTS (SELECT 1 FROM vendor_items vi WHERE vi.product_id = products.id AND LOWER(vi.vendor_item_no) = ?) THEN 0 ELSE 1 END',
            [$lower, ...$vars, ...$vars, ...$vars, $lower]
        );
        $q->orderByRaw("CASE WHEN {$name} LIKE ? THEN 0 WHEN {$name} LIKE ? THEN 1 ELSE 2 END", [$phrase.'%', $first.'%']);
        // more of the typed words (or their expansions) in the name = better
        $score = [];
        $bind = [];
        foreach ($groups as $g) {
            $alt = [];
            foreach ([$g['words'], ...$g['alts']] as $ws) {
                $alt[] = '('.implode(' AND ', array_map(fn ($w) => "{$name} LIKE ?", $ws)).')';
                foreach ($ws as $w) {
                    $bind[] = '%'.$w.'%';
                }
            }
            $score[] = 'CASE WHEN '.implode(' OR ', $alt).' THEN 1 ELSE 0 END';
        }
        if ($score !== []) {
            $q->orderByRaw('('.implode(' + ', $score).') DESC', $bind);
        }

        return $q->orderByRaw('CASE WHEN EXISTS (SELECT 1 FROM inventories inv WHERE inv.product_id = products.id AND inv.qty > 0) THEN 0 ELSE 1 END');
    }

    /** Browse catalog (POS-style): department, vendor (via vendor_items), free-text filter. */
    public static function browseQuery(?int $departmentId = null, ?int $vendorId = null, string $filter = ''): Builder
    {
        $f = trim($filter);

        return Product::query()->where('active', true)->notArchived()
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->when($vendorId, fn ($q) => $q->where(function ($w) use ($vendorId) {
                $w->where('preferred_vendor_id', $vendorId)
                    ->orWhereHas('vendorItems', fn ($v) => $v->where('vendor_id', $vendorId));
            }))
            ->when($f !== '', fn ($q) => self::matching($q, $f))
            ->orderBy('name')->orderBy('id');
    }

    /** @return Collection<int, Product> */
    public static function search(string $term, int $limit = 30): Collection
    {
        return self::query($term)->limit($limit)->get();
    }
}
