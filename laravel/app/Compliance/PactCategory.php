<?php

namespace App\Compliance;

use App\Models\Product;

/**
 * PACT Act product category (15 U.S.C. 375): "cigarette" includes roll-your-own tobacco and ENDS (vapor), excludes cigars;
 * smokeless tobacco is covered separately. Other products flagged pact_covered report as "other".
 */
class PactCategory
{
    public const LABELS = ['cigarette' => 'Cigarettes', 'ryo' => 'Roll-your-own tobacco', 'smokeless' => 'Smokeless tobacco', 'ends' => 'ENDS / vapor', 'other' => 'Other covered'];

    public static function of(Product $p): ?string
    {
        return match ($p->tax_class?->value) {
            'cigarette_stamped' => 'cigarette',
            'ryo' => 'ryo',
            'snuff_chew' => 'smokeless',
            'vapor' => 'ends',
            default => $p->pact_covered ? 'other' : null,
        };
    }

    /** Subject to state MSA directory listing (cigarettes and RYO). */
    public static function directoryType(Product $p): ?string
    {
        return match ($p->tax_class?->value) {
            'cigarette_stamped' => 'cigarette',
            'ryo' => 'ryo',
            default => null,
        };
    }
}
