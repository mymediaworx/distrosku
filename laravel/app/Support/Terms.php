<?php

namespace App\Support;

/** 0.20.6: standard payment terms for the customer Terms dropdown. Stored as the label text (DunningService::termsDays reads it). */
class Terms
{
    public const STANDARD = ['COD', 'Due on receipt', 'Prepaid', 'Net 7', 'Net 10', 'Net 15', 'Net 30', 'Net 45', 'Net 60', '2/10 Net 30'];

    /** Map a free-text value to a standard option, or null when it does not match one. */
    public static function normalize(?string $v): ?string
    {
        $s = strtolower(trim((string) $v));
        if ($s === '') {
            return null;
        }
        $s = preg_replace('/\s+/', ' ', $s);
        $alias = ['cod' => 'COD', 'c.o.d.' => 'COD', 'c.o.d' => 'COD', 'cash' => 'COD', 'cash on delivery' => 'COD',
            'due on receipt' => 'Due on receipt', 'due upon receipt' => 'Due on receipt', 'upon receipt' => 'Due on receipt', 'receipt' => 'Due on receipt',
            'prepaid' => 'Prepaid', 'prepay' => 'Prepaid', 'cia' => 'Prepaid', 'cash in advance' => 'Prepaid'];
        if (isset($alias[$s])) {
            return $alias[$s];
        }
        if (preg_match('#^2\s*/\s*10\s*,?\s*n(et)?\s*/?\s*30$#', $s)) {
            return '2/10 Net 30';
        }
        if (preg_match('#^n(et)?\s*[-/]?\s*(\d+)(\s*days?)?$#', $s, $m) && in_array('Net '.(int) $m[2], self::STANDARD, true)) {
            return 'Net '.(int) $m[2];
        }

        return null;
    }

    /** Options for the dropdown; a non-standard current value stays selectable so no data is lost. */
    public static function options(?string $current = null): array
    {
        $o = array_combine(self::STANDARD, self::STANDARD);
        if ($current !== null && $current !== '' && ! isset($o[$current])) {
            $o[$current] = $current.' (existing)';
        }

        return $o;
    }
}
