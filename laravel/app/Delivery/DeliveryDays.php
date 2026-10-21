<?php

namespace App\Delivery;

use App\Models\Customer;
use App\Models\ShipTo;
use Carbon\CarbonInterface;

/** Delivery days are stored as csv of mon..sun on the customer; a ship-to's own list overrides it. */
class DeliveryDays
{
    public const DAYS = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];

    /** @return list<string> */
    public static function parse(mixed $csv): array
    {
        $csv = is_array($csv) ? implode(',', $csv) : (string) $csv;

        return array_values(array_intersect(array_keys(self::DAYS), array_map(fn ($d) => strtolower(trim($d)), explode(',', $csv))));
    }

    public static function toCsv(mixed $days): ?string
    {
        $d = self::parse(is_array($days) ? implode(',', $days) : (string) $days);

        return $d ? implode(',', $d) : null;
    }

    /** @return list<string> */
    public static function effective(?ShipTo $st, ?Customer $c): array
    {
        return self::parse($st?->delivery_days) ?: self::parse($c?->delivery_days);
    }

    public static function key(CarbonInterface $date): string
    {
        return strtolower(substr($date->englishDayOfWeek, 0, 3));
    }
}
