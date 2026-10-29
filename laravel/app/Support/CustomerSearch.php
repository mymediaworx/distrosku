<?php

namespace App\Support;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * 0.20.20: customer lookup for every customer picker (sales order, POS, van sale, price rules, ship-tos, reports...).
 * Same idea as ProductSearch: the text is split on spaces, EVERY word must match somewhere (any order), ignoring case
 * and punctuation. A word matches the account number, name, DBA, phone (digits only, so 502-555-0100 / (502) 555 0100
 * both work), email, or the city of any of the customer's ship-to addresses.
 */
final class CustomerSearch
{
    private const MAX_WORDS = 8;

    /** Punctuation ignored when comparing (same set as ProductSearch, plus space for phone numbers). */
    private const STRIP = ['&', '.', '/', '-', "'", ',', ':', ';', '_', '(', ')', '#', '*', '!', '+', '’', ' '];

    /** @return list<string> typed words, lower-case letters and digits only */
    public static function words(string $text): array
    {
        return array_slice(ProductSearch::words($text), 0, self::MAX_WORDS);
    }

    private static function norm(string $expr): string
    {
        $e = "LOWER(COALESCE({$expr}, ''))";
        foreach (self::STRIP as $ch) {
            $e = "REPLACE({$e}, '".str_replace("'", "''", $ch)."', '')";
        }

        return $e;
    }

    /** Narrow a customers query to the typed words (every word must match, any order). Does not order. */
    public static function matching(Builder $q, string $search): Builder
    {
        $term = trim($search);
        if ($term === '') {
            return $q;
        }
        $words = self::words($term);
        if ($words === []) { // only punctuation typed
            return $q->where(fn ($w) => $w->where('customers.name', 'like', '%'.$term.'%')->orWhere('customers.dba', 'like', '%'.$term.'%'));
        }
        foreach ($words as $w) {
            $like = '%'.$w.'%';
            $q->where(function (Builder $m) use ($like) {
                $m->whereRaw(self::norm('customers.code').' LIKE ?', [$like])
                    ->orWhereRaw(self::norm('customers.name').' LIKE ?', [$like])
                    ->orWhereRaw(self::norm('customers.dba').' LIKE ?', [$like])
                    ->orWhereRaw(self::norm('customers.phone').' LIKE ?', [$like])
                    ->orWhereRaw(self::norm('customers.email').' LIKE ?', [$like])
                    ->orWhereRaw('customers.id IN (SELECT customer_id FROM ship_tos WHERE '.self::norm('city').' LIKE ? OR '.self::norm('phone').' LIKE ?)', [$like, $like]);
            });
        }

        return $q;
    }

    /** Best first: exact account number, then names / DBA that start with the text, then name. */
    public static function query(?string $search = null, bool $activeOnly = true): Builder
    {
        $q = Customer::query();
        if ($activeOnly) {
            $q->where('customers.active', true);
        }
        $term = trim((string) $search);
        if ($term === '') {
            return $q->orderBy('customers.code')->orderBy('customers.name');
        }
        self::matching($q, $term);
        $canon = implode('', self::words($term));
        $first = self::words($term)[0] ?? '';
        $q->orderByRaw('CASE WHEN '.self::norm('customers.code').' = ? THEN 0 ELSE 1 END', [$canon]);
        $q->orderByRaw('CASE WHEN '.self::norm('customers.name').' LIKE ? OR '.self::norm('customers.dba').' LIKE ? THEN 0 WHEN '.self::norm('customers.name').' LIKE ? OR '.self::norm('customers.dba').' LIKE ? THEN 1 ELSE 2 END',
            [$canon.'%', $canon.'%', $first.'%', $first.'%']);

        return $q->orderBy('customers.name')->orderBy('customers.id');
    }

    /**
     * Rows for a picker: id, account number, name, DBA, phone, city (the default / first ship-to's city), label.
     *
     * @return list<array{id: int, code: string, name: string, dba: ?string, phone: ?string, city: ?string, label: string}>
     */
    public static function results(string $search, int $limit = 20, bool $activeOnly = true): array
    {
        return self::query($search, $activeOnly)->limit($limit)->get()->map(fn (Customer $c) => self::row($c))->all();
    }

    public static function row(Customer $c): array
    {
        $city = $c->shipTos()->orderByDesc('is_default')->orderBy('id')->value('city');

        return ['id' => (int) $c->id, 'code' => (string) $c->code, 'name' => (string) $c->name, 'dba' => $c->dba ?: null,
            'phone' => $c->phone ?: null, 'city' => $city ?: null, 'label' => $c->labelWithAccount()];
    }

    /** Options for a Filament searchable Select: id => "40505 — Name". Blank search lists the first accounts. */
    public static function options(?string $search = null, int $limit = 50, bool $activeOnly = false): array
    {
        return self::query($search, $activeOnly)->limit($limit)->get(['customers.id', 'customers.code', 'customers.name', 'customers.dba'])
            ->mapWithKeys(fn (Customer $c) => [$c->id => $c->labelWithAccount()])->all();
    }
}
