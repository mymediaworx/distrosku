<?php

/**
 * 0.20.19: product search abbreviations / synonyms (App\Support\ProductSearch).
 *
 * Left = what someone types (case and punctuation are ignored: "b&m", "B & M" and "bm" are the same thing).
 * Right = what it means; one string, or a list of alternative strings. Every word on the right must appear
 * in the product (any order). The typed text itself still matches too (as a whole word), so a product that
 * really is called "SS" is still found. A key can be several words ("black n mild").
 *
 * To add one, add a line; nothing else to change.
 */
return [
    'synonyms' => [
        // Black & Mild
        'bm' => 'black & mild',
        'b&m' => 'black & mild',
        'b m' => 'black & mild',
        'b n m' => 'black & mild',
        'black n mild' => 'black & mild',
        'black and mild' => 'black & mild',
        'black n milds' => 'black & mild',
        'blk' => 'black',
        // Swisher / Dutch / other cigar brands
        'ss' => 'swisher sweets',
        'dm' => 'dutch masters',
        'wo' => 'white owl',
        'bw' => 'backwoods',
        'bkw' => 'backwoods',
        // Cigarettes / tobacco
        'ft' => 'filter tip',
        'ryo' => 'roll your own',
        'mst' => 'moist',
        'ment' => 'menthol',
        'reg' => 'regular',
        // Pack sizes / units
        'cs' => 'case',
        'ctn' => 'carton',
        'pk' => 'pack',
        'bx' => 'box',
        'ea' => 'each',
        // Flavors
        'str' => 'strawberry',
        'straw' => 'strawberry',
        'blu' => 'blue',
        'wm' => 'watermelon',
        'van' => 'vanilla',
        'choc' => 'chocolate',
        'cher' => 'cherry',
    ],
];
