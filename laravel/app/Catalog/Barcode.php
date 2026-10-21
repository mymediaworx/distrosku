<?php

namespace App\Catalog;

use Picqer\Barcode\BarcodeGeneratorPNG;
use Picqer\Barcode\BarcodeGeneratorSVG;

/** CODE128 barcodes (value = SKU code). SVG for the browser, PNG data URI for dompdf. */
class Barcode
{
    public static function svg(string $value, int $scale = 2, int $height = 50): string
    {
        return (new BarcodeGeneratorSVG)->getBarcode($value, BarcodeGeneratorSVG::TYPE_CODE_128, $scale, $height);
    }

    public static function pngDataUri(string $value, int $scale = 2, int $height = 50): string
    {
        return 'data:image/png;base64,'.base64_encode((new BarcodeGeneratorPNG)->getBarcode($value, BarcodeGeneratorPNG::TYPE_CODE_128, $scale, $height));
    }
}
