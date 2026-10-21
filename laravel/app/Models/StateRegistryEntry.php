<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 0.18.1: one product on a state product registry (e.g. KY CHFS hemp-derived cannabinoid registry). */
class StateRegistryEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['first_seen_on' => 'date', 'last_seen_on' => 'date', 'removed_on' => 'date'];
    }

    public function listings()
    {
        return $this->hasMany(VapeDirectoryListing::class, 'registry_entry_id');
    }

    /** Lower-case, accents/punctuation stripped, "&" = "and", single spaces. */
    public static function norm(?string $s): string
    {
        $s = mb_strtolower(trim((string) $s));
        $s = str_replace(['&', '™', '®', '’', "'"], [' and ', '', '', '', ''], $s);
        $s = (string) preg_replace('/[^a-z0-9]+/u', ' ', \Illuminate\Support\Str::ascii($s));

        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    /** "brand name" key; the brand is not repeated when the name already starts with it. */
    public static function key(?string $brand, ?string $name): string
    {
        $b = self::norm($brand);
        $n = self::norm($name);

        return $b !== '' && ! str_starts_with($n, $b) ? trim("$b $n") : $n;
    }

    public function label(): string
    {
        return "#{$this->record_id} {$this->brand} | {$this->product_name}";
    }
}
