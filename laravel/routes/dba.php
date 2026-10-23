<?php

use App\Http\Controllers\DbaController;
use Illuminate\Support\Facades\Route;

// 0.20.8: instance manager (dba.distrosku.com). Loaded WITHOUT the web group (no session, cookies or CSRF);
// every route is HMAC-signed (VerifyDbaSignature) and stays reachable while the instance is suspended so the manager can resume.
Route::prefix('dba-api')->controller(DbaController::class)->group(function () {
    Route::post('/status', 'status');
    Route::post('/backups', 'backups');
    Route::post('/backup', 'backup');
    Route::post('/restore', 'restore');
    Route::post('/suspend', 'suspend');
    Route::post('/resume', 'resume');
    Route::post('/rules/preview', 'rulesPreview');
    Route::post('/rules/apply', 'rulesApply');
    Route::post('/rules/export', 'rulesExport');
});
