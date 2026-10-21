<?php

namespace App\Imports\Jobs;

use App\Imports\ImportRunner;
use App\Models\ImportBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Background worker for an import: a few chunks per job, then re-queues itself (cron queue:work --max-time=50). */
class RunImportStep implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $batchId) {}

    public function handle(ImportRunner $runner): void
    {
        $until = microtime(true) + 25;
        while (($b = ImportBatch::find($this->batchId)) && $runner->step($b)) {
            if (microtime(true) > $until) {
                self::dispatch($this->batchId);

                return;
            }
        }
    }
}
