<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Collision-free document numbers (row lock on the sequences table). */
final class Sequence
{
    public static function next(string $prefix, ?string $modelClass = null, int $pad = 6): string
    {
        return DB::transaction(function () use ($prefix, $modelClass, $pad) {
            $row = DB::table('sequences')->where('name', $prefix)->lockForUpdate()->first();
            if (! $row) {
                $start = 1;
                if ($modelClass) { // continue after any numbers created before sequences existed
                    foreach ($modelClass::where('number', 'like', $prefix.'%')->pluck('number') as $n) {
                        $digits = (int) preg_replace('/\D/', '', substr($n, strlen($prefix)));
                        $start = max($start, $digits + 1);
                    }
                }
                DB::table('sequences')->insertOrIgnore(['name' => $prefix, 'next_value' => $start, 'created_at' => now(), 'updated_at' => now()]);
                $row = DB::table('sequences')->where('name', $prefix)->lockForUpdate()->first();
            }
            $value = (int) $row->next_value;
            DB::table('sequences')->where('name', $prefix)->update(['next_value' => $value + 1, 'updated_at' => now()]);

            return $prefix.str_pad((string) $value, $pad, '0', STR_PAD_LEFT);
        });
    }
}
