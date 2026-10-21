<?php

namespace App\Imports;

use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/** Streams rows from CSV / XLSX (first sheet) without loading the whole file (shared-hosting memory safe). */
class SheetReader
{
    /** @return \Generator<int, list<string>> */
    public static function rows(string $path, ?string $name = null): \Generator
    {
        $ext = strtolower(pathinfo($name ?? $path, PATHINFO_EXTENSION));
        if (in_array($ext, ['csv', 'txt'], true)) {
            $o = new CsvOptions;
            $o->FIELD_DELIMITER = self::sniff($path);
            $r = new CsvReader($o);
        } elseif ($ext === 'xlsx') {
            $r = new XlsxReader;
        } else {
            throw new RuntimeException('Upload a .csv or .xlsx file.');
        }
        $r->open($path);
        try {
            foreach ($r->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    yield array_map(fn ($c) => $c instanceof \DateTimeInterface ? $c->format('Y-m-d') : (is_bool($c) ? ($c ? '1' : '0') : trim((string) $c)), $row->toArray());
                }
                break; // first sheet only
            }
        } finally {
            $r->close();
        }
    }

    private static function sniff(string $path): string
    {
        $line = (string) fgets($h = fopen($path, 'r'));
        fclose($h);
        $c = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($c);

        return (string) array_key_first($c);
    }
}
