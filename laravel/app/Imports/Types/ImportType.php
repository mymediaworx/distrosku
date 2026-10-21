<?php

namespace App\Imports\Types;

use App\Imports\RowResult;
use App\Models\ImportBatch;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** One import type: its columns, a read-only evaluate() (preview / dry run) and apply() (commit). */
abstract class ImportType
{
    abstract public function key(): string;

    abstract public function label(): string;

    /** @return array<string, array{label:string, required?:bool, help?:string, example?:string}> */
    abstract public function fields(): array;

    /** Read-only: validate, find the existing record by natural key, compute action + diff. */
    abstract public function evaluate(array $v, ImportBatch $b): RowResult;

    /** Write one row (already evaluated as valid). Returns action. Runs inside a transaction. */
    abstract public function apply(array $v, ImportBatch $b, RowResult $r): string;

    /** Called once after the last row is committed. */
    public function finish(ImportBatch $b): void {}

    public function needsVendor(): bool
    {
        return false;
    }

    // ---- value helpers ----
    protected static function s(array $v, string $k): ?string
    {
        $x = $v[$k] ?? null;
        $x = is_string($x) ? trim($x) : ($x === null ? null : trim((string) $x));

        return $x === '' ? null : $x;
    }

    /** Numeric string or null; $err gets a message when present but invalid. */
    protected static function num(array $v, string $k, array &$err, ?string $label = null, bool $allowNegative = false): ?string
    {
        $x = static::s($v, $k);
        if ($x === null) {
            return null;
        }
        $x = str_replace([',', '$', ' '], '', $x);
        if (! is_numeric($x) || (! $allowNegative && (float) $x < 0)) {
            $err[] = ($label ?? $k).": \"{$v[$k]}\" is not a valid ".($allowNegative ? 'number' : 'non-negative number').'.';

            return null;
        }

        return number_format((float) $x, 4, '.', '');
    }

    protected static function bool(array $v, string $k, array &$err): ?bool
    {
        $x = static::s($v, $k);
        if ($x === null) {
            return null;
        }
        $l = strtolower($x);
        if (in_array($l, ['1', 'y', 'yes', 'true', 't', 'x', 'active'], true)) {
            return true;
        }
        if (in_array($l, ['0', 'n', 'no', 'false', 'f', 'inactive'], true)) {
            return false;
        }
        $err[] = "{$k}: \"{$x}\" should be yes/no.";

        return null;
    }

    protected static function date(array $v, string $k, array &$err): ?string
    {
        $x = static::s($v, $k);
        if ($x === null) {
            return null;
        }
        try {
            if (is_numeric($x) && (float) $x > 20000 && (float) $x < 80000) { // Excel serial date
                return Carbon::create(1899, 12, 30)->addDays((int) $x)->toDateString();
            }

            return Carbon::parse($x)->toDateString();
        } catch (Throwable) {
            $err[] = "{$k}: \"{$x}\" is not a date.";

            return null;
        }
    }

    protected static function required(array $v, array $keys, array &$err): void
    {
        foreach ($keys as $k => $label) {
            if (static::s($v, $k) === null) {
                $err[] = "{$label} is required.";
            }
        }
    }

    /** Diff of attributes vs a model (or null for create). Only non-null incoming values. */
    protected static function diff(?Model $m, array $attrs): array
    {
        $d = [];
        foreach ($attrs as $k => $new) {
            if ($new === null) {
                continue;
            }
            $old = $m?->getAttribute($k);
            $old = $old instanceof \BackedEnum ? $old->value : ($old instanceof \DateTimeInterface ? $old->format('Y-m-d') : $old);
            $cmp = fn ($x) => is_bool($x) ? (int) $x : (is_numeric($x) ? (float) $x : (string) $x);
            if ($m === null || $old === null || $cmp($old) !== $cmp($new)) {
                $d[$k] = [$old, is_bool($new) ? (int) $new : $new];
            }
        }

        return $d;
    }

    protected static function filled(array $attrs): array
    {
        return array_filter($attrs, fn ($x) => $x !== null);
    }
}
