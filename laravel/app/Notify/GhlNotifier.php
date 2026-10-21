<?php

namespace App\Notify;

use App\Marketing\GhlClient;
use App\Marketing\Jobs\MarketingJob;
use App\Marketing\MarketingSettings;
use App\Marketing\MarketingSync;
use App\Marketing\SkipException;
use App\Models\Customer;
use App\Models\GhlSyncLog;
use App\Models\TransactionalTemplate;
use RuntimeException;

/**
 * 0.19: transactional email / SMS through BrandWorx Marketing (POST /conversations/messages, LC Email / LC Phone of the location).
 * Sends only when ALL hold: module enabled, "Send transactional messages" on, template enabled, customer opted in to that
 * channel, contact has the email / phone. Otherwise the attempt is logged as skipped. OFF by default.
 */
class GhlNotifier implements TransactionalNotifier
{
    public function __construct(private GhlClient $client) {}

    public function notify(string $template, Customer $customer, array $vars = []): array
    {
        $out = [];
        foreach (['email', 'sms'] as $ch) {
            $t = TransactionalTemplate::where('key', $template)->where('channel', $ch)->first();
            $why = match (true) {
                ! MarketingSettings::notifyEnabled() => 'sending off',
                ! $t || ! $t->enabled => 'template off',
                $ch === 'email' && (! $customer->email_opt_in || ! $customer->email) => 'no email opt-in',
                $ch === 'sms' && (! $customer->sms_opt_in || ! $customer->phone) => 'no SMS opt-in',
                default => null,
            };
            if ($why) {
                $out[$ch] = 'skipped: '.$why;

                continue;
            }
            $dedupe = isset($vars['order']) ? mb_substr("notify:{$template}:{$ch}:{$customer->id}:{$vars['order']}", 0, 120) : null;
            if ($log = GhlSyncLog::start('notify', "{$template}:{$ch}", $customer, $dedupe)) {
                MarketingJob::dispatch('notify', $log->id, ['customer_id' => $customer->id, 'template' => $template, 'channel' => $ch, 'vars' => $vars])->afterCommit();
                $out[$ch] = 'queued';
            }
        }

        return $out;
    }

    /** Job body: one message. */
    public function deliver(string $template, string $channel, Customer $c, array $vars): string
    {
        if (! MarketingSettings::notifyEnabled()) {
            throw new SkipException('Transactional sending is off.');
        }
        $t = TransactionalTemplate::where('key', $template)->where('channel', $channel)->where('enabled', true)->first()
            ?? throw new SkipException('Template is off.');
        $id = $c->ghl_contact_id ?: app(MarketingSync::class)->syncContact($c);
        $m = $t->render($vars + ['customer' => $c->name, 'company' => config('app.name')]);
        $payload = $channel === 'email'
            ? ['type' => 'Email', 'contactId' => $id, 'subject' => $m['subject'], 'html' => nl2br(e($m['body'])), 'message' => $m['body'], 'status' => 'pending']
            : ['type' => 'SMS', 'contactId' => $id, 'message' => $m['body'], 'status' => 'pending'];
        $r = $this->client->sendMessage($payload);
        if (! $r->successful()) {
            throw new RuntimeException("Send {$channel} HTTP {$r->status()}: ".mb_substr((string) ($r->json('message') ?? ''), 0, 200), $r->status());
        }

        return "{$channel} sent, message ".($r->json('messageId') ?? '?');
    }
}
