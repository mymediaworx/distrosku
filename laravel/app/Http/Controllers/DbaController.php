<?php

namespace App\Http\Controllers;

use App\Dba\BackupService;
use App\Dba\InstanceState;
use App\Dba\RulesApplier;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/** 0.20.8: signed endpoints for the instance manager (dba.distrosku.com). Middleware VerifyDbaSignature guards all of them. */
class DbaController extends Controller
{
    public function status(BackupService $b): JsonResponse
    {
        $dbOk = true;
        try {
            DB::select('select 1');
        } catch (Throwable) {
            $dbOk = false;
        }
        $q = fn (string $t) => rescue(fn () => DB::table($t)->count(), null, false);
        $dir = rescue(fn () => $b->dir(), null, false);

        return response()->json([
            'app' => 'distrosku-erp', 'version' => config('erp.version'), 'instance' => $b->instance(), 'install_id' => Setting::installId(),
            'time' => now()->toIso8601String(), 'php' => PHP_VERSION, 'db_ok' => $dbOk,
            'suspended' => InstanceState::suspended(), 'suspended_reason' => Setting::get('dba_suspended_reason'),
            'queue' => ['pending' => $q('jobs'), 'failed' => $q('failed_jobs')],
            'disk' => ['free_bytes' => $dir ? @disk_free_space($dir) ?: null : null, 'total_bytes' => $dir ? @disk_total_space($dir) ?: null : null],
            'rules_version' => app(RulesApplier::class)->currentVersion(),
            'last_backup' => json_decode((string) Setting::get('dba_last_backup', 'null'), true),
            'backups' => array_slice(rescue(fn () => $b->list(), [], false), 0, 40),
        ]);
    }

    public function backups(BackupService $b, Request $r): JsonResponse
    {
        return response()->json(['backups' => $b->list((bool) $r->input('verify'))]);
    }

    public function backup(BackupService $b, Request $r): JsonResponse
    {
        try {
            return response()->json(['ok' => true, 'backup' => array_diff_key($b->create('manager', $this->by($r)), ['path' => 1])]);
        } catch (Throwable $e) {
            Setting::put('dba_last_backup', json_encode(['status' => 'failed', 'at' => now()->toIso8601String(), 'error' => mb_substr($e->getMessage(), 0, 300)]));

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function restore(BackupService $b, Request $r): JsonResponse
    {
        $file = (string) $r->input('file');
        if ($file === '' || str_contains($file, '/') || $r->input('confirm') !== $b->instance()) {
            return response()->json(['ok' => false, 'error' => 'file (name only) and confirm=<instance name> are required'], 422);
        }
        try {
            return response()->json(['ok' => true, 'result' => $b->restore($file, false, true, $this->by($r))]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function suspend(Request $r): JsonResponse
    {
        InstanceState::suspend($r->input('reason'), $this->by($r));

        return response()->json(['ok' => true, 'suspended' => true]);
    }

    public function resume(Request $r): JsonResponse
    {
        InstanceState::resume($this->by($r));

        return response()->json(['ok' => true, 'suspended' => false]);
    }

    public function rulesPreview(RulesApplier $a, Request $r): JsonResponse
    {
        try {
            return response()->json(['ok' => true, 'preview' => $a->preview((array) $r->input('ruleset'))]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function rulesApply(RulesApplier $a, Request $r): JsonResponse
    {
        try {
            return response()->json(['ok' => true, 'result' => $a->apply((array) $r->input('ruleset'), $this->by($r))]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function rulesExport(RulesApplier $a): JsonResponse
    {
        return response()->json(['ok' => true, 'ruleset' => $a->export()]);
    }

    private function by(Request $r): ?string
    {
        $by = $r->input('by');

        return is_string($by) ? mb_substr($by, 0, 120) : null;
    }
}
