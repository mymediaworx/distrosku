<?php

namespace App\Imports;

use App\Audit\Audit;
use App\Models\ImportBatch;
use App\Models\ImportMapping;
use App\Models\ImportRow;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;
use Throwable;

/**
 * Upload -> map -> validate (dry run, chunked) -> commit (chunked). Each step() handles CHUNK rows, so a big file
 * runs across many short requests (Livewire poll) and/or queued jobs (cron queue:work) - safe on shared hosting.
 */
class ImportRunner
{
    public const CHUNK = 250;

    public const MAX_ROWS = 100000;

    public static function canUse(?User $u): bool
    {
        return (bool) $u?->canModule('imports', 'manage');
    }

    public function upload(UploadedFile|string $file, string $type, User $by, ?int $vendorId = null, ?string $effectiveOn = null, ?string $originalName = null): ImportBatch
    {
        $handler = ImportRegistry::make($type);
        if ($handler->needsVendor() && ! $vendorId) {
            throw new RuntimeException('Choose the vendor for this price file.');
        }
        $name = $originalName ?? ($file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
            throw new RuntimeException('Upload a .csv or .xlsx file.');
        }
        $path = 'imports/'.now()->format('Ym').'/'.Str::random(20).'.'.$ext;
        Storage::disk('local')->put($path, $file instanceof UploadedFile ? $file->get() : file_get_contents($file));
        $b = ImportBatch::create(['type' => $type, 'status' => 'uploaded', 'original_name' => mb_substr($name, 0, 190), 'file_path' => $path,
            'vendor_id' => $vendorId, 'effective_on' => $effectiveOn ?: null, 'user_id' => $by->id]);
        \App\Support\TimeLimit::extend(300);
        $headers = null;
        $buf = [];
        $n = 0;
        foreach (SheetReader::rows(Storage::disk('local')->path($path), $name) as $cells) {
            if ($headers === null) {
                $headers = array_map(fn ($h) => mb_substr(trim((string) $h), 0, 80), $cells);

                continue;
            }
            if (! array_filter($cells, fn ($c) => $c !== '')) {
                continue; // blank line
            }
            if (++$n > self::MAX_ROWS) {
                throw new RuntimeException('File has more than '.number_format(self::MAX_ROWS).' rows; split it.');
            }
            $buf[] = ['import_batch_id' => $b->id, 'row_no' => $n + 1, 'raw' => json_encode($cells, JSON_UNESCAPED_UNICODE), 'status' => 'pending'];
            if (count($buf) >= 500) {
                ImportRow::insert($buf);
                $buf = [];
            }
        }
        if ($buf) {
            ImportRow::insert($buf);
        }
        if (! $headers) {
            throw new RuntimeException('The file is empty.');
        }
        $b->update(['headers' => $headers, 'total_rows' => $n, 'mapping' => $this->autoMap($handler, $headers, $vendorId)]);
        Audit::action('import_uploaded', $b, "{$handler->label()} import: {$name}, {$n} rows");

        return $b->fresh();
    }

    /** Header => field guess, or the newest saved mapping for this type/vendor whose headers all exist. */
    public function autoMap(Types\ImportType $h, array $headers, ?int $vendorId = null): array
    {
        $saved = ImportMapping::where('type', $h->key())->where('vendor_id', $vendorId)->latest('updated_at')->get()
            ->first(fn ($m) => ! array_diff(array_filter($m->mapping), $headers));
        if ($saved) {
            return $saved->mapping;
        }
        $norm = fn ($s) => preg_replace('/[^a-z0-9]/', '', strtolower($s));
        $map = [];
        foreach ($h->fields() as $k => $f) {
            foreach ($headers as $hd) {
                if (in_array($norm($hd), [$norm($k), $norm($f['label'])], true)) {
                    $map[$k] = $hd;
                    break;
                }
            }
        }

        return $map;
    }

    public function saveMapping(ImportBatch $b, string $name, User $by): ImportMapping
    {
        return ImportMapping::updateOrCreate(['type' => $b->type, 'vendor_id' => $b->vendor_id, 'name' => mb_substr($name, 0, 80)], ['mapping' => $b->mapping, 'user_id' => $by->id]);
    }

    /** Mapped values for one raw row. */
    public static function values(ImportBatch $b, array $raw): array
    {
        $idx = array_flip($b->headers ?? []);
        $v = [];
        foreach ((array) $b->mapping as $field => $header) {
            if ($header !== null && $header !== '' && isset($idx[$header])) {
                $v[$field] = $raw[$idx[$header]] ?? null;
            }
        }

        return $v;
    }

    public function setMapping(ImportBatch $b, array $mapping): void
    {
        if (! in_array($b->status, ['uploaded', 'validated'], true)) {
            throw new RuntimeException('This import is already being committed.');
        }
        $h = $b->handler();
        $mapping = array_intersect_key(array_filter($mapping, fn ($x) => $x !== null && $x !== ''), $h->fields());
        $missing = array_keys(array_filter($h->fields(), fn ($f, $k) => ($f['required'] ?? false) && ! isset($mapping[$k]), ARRAY_FILTER_USE_BOTH));
        if ($b->type === 'vendor_costs' && ! isset($mapping['vendor_item_no']) && ! isset($mapping['upc'])) {
            $missing[] = 'vendor_item_no or upc';
        }
        if ($missing) {
            throw new RuntimeException('Map the required columns: '.implode(', ', $missing).'.');
        }
        $b->update(['mapping' => $mapping, 'status' => 'uploaded', 'cursor' => 0, 'validated_at' => null]);
    }

    /** Start validation (dry run). */
    public function startValidation(ImportBatch $b): void
    {
        $this->setMapping($b, (array) $b->mapping);
        $b->rows()->update(['status' => 'pending', 'errors' => null, 'diff' => null, 'action' => null, 'key_label' => null]);
        $b->update(['status' => 'validating', 'cursor' => 0, 'valid_rows' => 0, 'error_rows' => 0, 'message' => null]);
        Jobs\RunImportStep::dispatch($b->id);
    }

    public function startCommit(ImportBatch $b, User $by): void
    {
        if ($b->status !== 'validated') {
            throw new RuntimeException('Validate (dry run) the file first.');
        }
        if ($b->valid_rows === 0) {
            throw new RuntimeException('No valid rows to import.');
        }
        $b->update(['status' => 'committing', 'cursor' => 0, 'created_count' => 0, 'updated_count' => 0, 'unchanged_count' => 0]);
        Audit::action('import_started', $b, "Commit started by {$by->name}: {$b->valid_rows} valid rows, {$b->error_rows} skipped");
        Jobs\RunImportStep::dispatch($b->id);
    }

    /** Process one chunk of the current stage. Returns true when more work remains. Lock-guarded (poll + queue may race). */
    public function step(ImportBatch $b): bool
    {
        if (! in_array($b->status, ['validating', 'committing'], true)) {
            return false;
        }
        $lock = Cache::lock("import-step-{$b->id}", 120);
        if (! $lock->get()) {
            return true;
        }
        try {
            $b->refresh();
            \App\Support\TimeLimit::extend(120);

            return $b->status === 'validating' ? $this->validateChunk($b) : ($b->status === 'committing' ? $this->commitChunk($b) : false);
        } catch (Throwable $e) {
            $b->update(['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 250)]);
            report($e);

            return false;
        } finally {
            $lock->release();
        }
    }

    public function runToEnd(ImportBatch $b, int $maxSteps = 100000): ImportBatch
    {
        while ($maxSteps-- > 0 && $this->step($b->fresh())) {
        }

        return $b->fresh();
    }

    private function validateChunk(ImportBatch $b): bool
    {
        $h = $b->handler();
        $rows = $b->rows()->where('row_no', '>', $b->cursor)->orderBy('row_no')->limit(self::CHUNK)->get();
        foreach ($rows as $row) {
            $r = $h->evaluate(self::values($b, $row->raw), $b);
            if ($r->key !== null && ! $r->errors && ($dup = $b->rows()->where('key_label', $r->key)->where('row_no', '<', $row->row_no)->whereIn('status', ['valid'])->value('row_no'))) {
                $r->errors[] = "Duplicate of row {$dup} in this file.";
            }
            $row->update(['status' => $r->errors ? 'error' : ($r->unmatched ? 'unmatched' : 'valid'), 'errors' => $r->errors ?: null,
                'action' => $r->action, 'diff' => $r->diff ?: null, 'key_label' => $r->key !== null ? mb_substr($r->key, 0, 190) : null]);
        }
        if ($rows->isNotEmpty()) {
            $b->update(['cursor' => $rows->last()->row_no]);

            return true;
        }
        $b->update(['status' => 'validated', 'validated_at' => now(), 'valid_rows' => $b->rows()->where('status', 'valid')->count(),
            'error_rows' => $b->rows()->whereIn('status', ['error', 'unmatched'])->count(), 'cursor' => 0]);

        return false;
    }

    private function commitChunk(ImportBatch $b): bool
    {
        $h = $b->handler();
        $rows = $b->rows()->where('row_no', '>', $b->cursor)->where('status', 'valid')->orderBy('row_no')->limit(self::CHUNK)->get();
        $counts = ['create' => 0, 'update' => 0, 'schedule' => 0, 'unchanged' => 0];
        foreach ($rows as $row) {
            try {
                DB::transaction(function () use ($h, $b, $row, &$counts) {
                    $r = $h->evaluate(self::values($b, $row->raw), $b); // re-check: data may have changed since the dry run
                    if (! $r->ok()) {
                        throw new RuntimeException(implode(' ', $r->errors ?: ['No longer matches a product.']));
                    }
                    $action = $r->action === 'unchanged' ? 'unchanged' : $h->apply(self::values($b, $row->raw), $b, $r);
                    $counts[$action] = ($counts[$action] ?? 0) + 1;
                    $row->update(['status' => 'committed', 'action' => $action, 'diff' => $r->diff ?: null]);
                });
            } catch (Throwable $e) {
                $row->update(['status' => 'failed', 'errors' => [mb_substr($e->getMessage(), 0, 500)]]);
            }
        }
        if ($rows->isNotEmpty()) {
            $b->update(['cursor' => $rows->last()->row_no, 'created_count' => $b->created_count + $counts['create'],
                'updated_count' => $b->updated_count + $counts['update'] + $counts['schedule'], 'unchanged_count' => $b->unchanged_count + $counts['unchanged']]);

            return true;
        }
        DB::transaction(fn () => $h->finish($b));
        $failed = $b->rows()->where('status', 'failed')->count();
        $b->update(['status' => 'done', 'committed_at' => now(), 'message' => $failed ? "{$failed} row(s) failed at commit; see the error report." : null]);
        Audit::action('import_committed', $b, "{$h->label()}: {$b->created_count} created, {$b->updated_count} updated, {$b->unchanged_count} unchanged, {$failed} failed");

        return false;
    }

    /** CSV of rows with errors / unmatched / failed (original columns + row # + reason). */
    public function errorReportCsv(ImportBatch $b): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_merge(['Row', 'Status', 'Problem'], $b->headers ?? []));
        $b->rows()->whereIn('status', ['error', 'unmatched', 'failed'])->orderBy('row_no')->chunk(1000, function ($rows) use ($out) {
            foreach ($rows as $r) {
                fputcsv($out, array_merge([$r->row_no, $r->status, $r->status === 'unmatched' ? 'No matching product' : implode(' ', (array) $r->errors)], $r->raw));
            }
        });
        rewind($out);

        return stream_get_contents($out);
    }

    /** Blank template (headers + one example row) as CSV or XLSX; returns file path. */
    public static function template(string $type, string $format = 'csv'): string
    {
        $h = ImportRegistry::make($type);
        $labels = array_map(fn ($f) => $f['label'], $h->fields());
        $example = array_map(fn ($f) => (string) ($f['example'] ?? ''), $h->fields());
        $path = storage_path('app/private/import-templates');
        @mkdir($path, 0775, true);
        $file = "{$path}/{$type}-template.{$format}";
        if ($format === 'xlsx') {
            $w = new XlsxWriter;
            $w->openToFile($file);
            $w->addRow(Row::fromValues(array_values($labels)));
            $w->addRow(Row::fromValues(array_values($example)));
            $w->close();
        } else {
            $f = fopen($file, 'w');
            fputcsv($f, array_values($labels));
            fputcsv($f, array_values($example));
            fclose($f);
        }

        return $file;
    }
}
