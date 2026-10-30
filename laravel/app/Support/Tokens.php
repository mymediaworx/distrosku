<?php

namespace App\Support;

use App\Models\Company;

/**
 * #10: one set of design tokens for the standalone screens (POS, warehouse, driver, portal, quick tools), driven by
 * Company Settings > Brand > Brand color. Each layout keeps its own default when no brand color is set.
 */
class Tokens
{
    public const DEFAULT_BRAND = '#1e3a8a';

    public const DRIVER_BRAND = '#0f766e';

    public const FONT = 'system-ui,-apple-system,"Segoe UI",Roboto,sans-serif';

    public static function brand(string $default = self::DEFAULT_BRAND): string
    {
        try {
            $c = Company::current()->brand_color ?? null;
        } catch (\Throwable) {
            $c = null;
        }

        return self::isHex($c) ? strtolower($c) : $default;
    }

    public static function isHex(mixed $v): bool
    {
        return is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1;
    }

    /** White or near-black text, whichever reads better on the color (WCAG relative luminance). */
    public static function onColor(string $hex): string
    {
        [$r, $g, $b] = array_map(function ($i) use ($hex) {
            $c = hexdec(substr($hex, $i, 2)) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, [1, 3, 5]);
        $l = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;

        return (1.05 / ($l + 0.05)) >= (($l + 0.05) / 0.0611) ? '#ffffff' : '#111111';
    }

    /** The :root block every standalone layout includes. */
    public static function css(string $default = self::DEFAULT_BRAND): string
    {
        $brand = self::brand($default);

        return ':root{--brand:'.$brand.';--on-brand:'.self::onColor($brand).';--ok:#047857;--danger:#b91c1c;--warn:#b45309;'
            .'--ink:#111;--muted:#64748b;--line:#e2e8f0;--bg:#f4f5f7;--font:'.self::FONT.'}';
    }
}
