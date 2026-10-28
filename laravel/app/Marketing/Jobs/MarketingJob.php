<?php

namespace App\Marketing\Jobs;

use App\Marketing\MarketingSettings;
use App\Marketing\MarketingSync;
use App\Marketing\SkipException;
use App\Models\Customer;
use App\Models\GhlSyncLog;
use App\Models\SalesOrder;
use App\Notify\GhlNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** 0.19: one queued Marketing task (contact upsert, order event, notification). Retried with backoff. */
class MarketingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public string $task, public int $logId, public array $data) {}

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(MarketingSync $sync): void
    {
        $log = GhlSyncLog::find($this->logId);
        if (! $log) {
            return;
        }
        if (! MarketingSettings::enabled()) {
            $log->finish('skipped', null, null, 'Marketing is off.');

            return;
        }
        try {
            $c = Customer::findOrFail($this->data['customer_id']);
            $so = ! empty($this->data['sales_order_id']) ? SalesOrder::find($this->data['sales_order_id']) : null;
            $summary = match ($this->task) {
                'contact' => 'contact '.$sync->syncContact($c).($sync->lastNotes ? ' ('.implode('; ', $sync->lastNotes).')' : ''),
                'event' => $sync->pushEvent($this->data['event'], $c, $so, $this->data['extra'] ?? []),
                'notify' => app(GhlNotifier::class)->deliver($this->data['template'], $this->data['channel'], $c, $this->data['vars'] ?? []),
            };
            $log->finish('ok', 200, $summary);
        } catch (SkipException $e) {
            $log->finish('skipped', null, null, $e->getMessage());
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            $final = $this->attempts() >= $this->tries || in_array($code, [400, 401, 403, 404, 422], true);
            $log->finish($final ? 'failed' : 'queued', $code ?: null, null, $e->getMessage());
            if (! $final) {
                throw $e; // retry with backoff
            }
        }
    }
}
