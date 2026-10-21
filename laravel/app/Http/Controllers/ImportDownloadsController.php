<?php

namespace App\Http\Controllers;

use App\Imports\ImportRegistry;
use App\Imports\ImportRunner;
use App\Models\ImportBatch;
use Illuminate\Http\Request;

class ImportDownloadsController extends Controller
{
    public function template(Request $r, string $type, string $format)
    {
        abort_unless(ImportRunner::canUse($r->user()), 403);
        abort_unless(isset(ImportRegistry::TYPES[$type]) && in_array($format, ['csv', 'xlsx'], true), 404);

        return response()->download(ImportRunner::template($type, $format), "{$type}-template.{$format}")->deleteFileAfterSend();
    }

    public function errors(Request $r, ImportBatch $batch)
    {
        abort_unless(ImportRunner::canUse($r->user()), 403);

        return response(app(ImportRunner::class)->errorReportCsv($batch), 200, [
            'Content-Type' => 'text/csv', 'Content-Disposition' => "attachment; filename=\"import-{$batch->id}-errors.csv\"",
        ]);
    }
}
