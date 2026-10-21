<?php

namespace App\Marketing;

use App\Marketing\Jobs\MarketingJob;
use App\Models\Customer;
use App\Models\GhlSyncLog;
use App\Models\SalesOrder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 0.19 BrandWorx Marketing v1:
 *  - customers -> contacts (upsert + custom fields; tags vega, type:*, state:*, route:* added; ERP opt-ins drive DND). One-way since 0.19.2.
 *  - order events -> tag on the contact (removed + re-added so "Tag added" workflow triggers fire every time) and, if set,
 *    a POST to the inbound-webhook workflow trigger URL with the order details.
 * Everything runs as queued, retried jobs and is written to ghl_sync_logs (Marketing > Sync log).
 */
class MarketingSync
{
    public const EVENTS = ['order_placed' => 'erp-order-placed', 'order_delivered' => 'erp-order-delivered', 'invoice_ready' => 'erp-invoice-ready',
        'invoice_due' => 'erp-invoice-due', 'invoice_overdue' => 'erp-invoice-overdue'];

    /** Notes from the last syncContact() (e.g. a custom field that was skipped), shown in the sync log. */
    public array $lastNotes = [];

    public function __construct(private GhlClient $client) {}

    /** Contacts per 10-second window for bulk pushes (each contact is about 2-3 API calls; the API allows 100 per 10 s). */
    public const BULK_PER_10S = 20;

    public static function queueContact(Customer $c, int $delaySeconds = 0): void
    {
        if (MarketingSettings::enabled() && ($log = GhlSyncLog::start('contact', 'upsert', $c))) {
            $job = MarketingJob::dispatch('contact', $log->id, ['customer_id' => $c->id])->afterCommit();
            if ($delaySeconds > 0) {
                $job->delay(now()->addSeconds($delaySeconds));
            }
        }
    }

    /** 0.19.2: queue many customers, spread out so the API rate limit is never hit. Returns the count queued. */
    public static function queueMany($query): int
    {
        $n = 0;
        $query->chunkById(200, function ($cs) use (&$n) {
            foreach ($cs as $c) {
                self::queueContact($c, intdiv($n, self::BULK_PER_10S) * 10);
                $n++;
            }
        });

        return $n;
    }

    public static function queueEvent(string $event, Customer $c, ?SalesOrder $so = null, array $extra = [], ?string $dedupe = null): void
    {
        if (MarketingSettings::enabled() && ($log = GhlSyncLog::start('event', $event, $so ?? $c, $dedupe))) {
            MarketingJob::dispatch('event', $log->id, ['event' => $event, 'customer_id' => $c->id, 'sales_order_id' => $so?->id, 'extra' => $extra])->afterCommit();
        }
    }

    public static function tags(Customer $c): array
    {
        $st = $c->shipTos()->orderBy('id')->first();
        $t = ['vega', 'type:'.Str::slug((string) $c->type)];
        if ($st?->state) {
            $t[] = 'state:'.strtolower($st->state);
        }
        if ($st?->route_name) {
            $t[] = 'route:'.Str::slug($st->route_name);
        }

        return $t; // ERP-owned tags only (added, never removed); consent tags belong to BrandWorx Marketing
    }

    public static function contactPayload(Customer $c): array
    {
        $st = $c->shipTos()->orderBy('id')->first();
        $p = array_filter([
            'name' => $c->name, 'companyName' => $c->name, 'email' => $c->email ?: null, 'phone' => $c->phone ?: null,
            'address1' => $st?->address, 'city' => $st?->city, 'state' => $st?->state, 'postalCode' => $st?->zip,
            'source' => 'Vega ERP',
        ], fn ($v) => $v !== null && $v !== '');
        // 0.19.2: one-way sync, the ERP opt-in switches drive consent.
        //  - opt-in OFF  -> DND on for that channel (always).
        //  - opt-in ON   -> DND cleared only on a contact the ERP itself created; on a contact that already existed in
        //                   BrandWorx Marketing (or before we know) we send nothing, so DND set there is never wiped.
        $dnd = [];
        foreach (['SMS' => 'sms_opt_in', 'Email' => 'email_opt_in'] as $ch => $f) {
            if (! $c->{$f}) {
                $dnd[$ch] = ['status' => 'active', 'message' => 'Vega ERP: no opt-in'];
            } elseif ($c->ghl_contact_id && $c->ghl_created_by_erp) {
                $dnd[$ch] = ['status' => 'inactive', 'message' => 'Vega ERP opt-in'];
            }
        }
        if ($dnd) {
            $p['dndSettings'] = $dnd;
        }

        return $p;
    }

    /** Job body: upsert one customer. Returns the contact id. */
    public function syncContact(Customer $c): string
    {
        if (! $c->email && ! $c->phone) {
            throw new SkipException('No email or phone on the customer; BrandWorx Marketing needs one to match a contact.');
        }
        $p = self::contactPayload($c);
        [$fields, $notes] = app(MarketingFields::class)->payload($c);
        if ($fields) {
            $p['customFields'] = $fields;
        }
        $r = $this->client->upsertContact($p);
        if (! $r->successful()) {
            throw new RuntimeException("Contact upsert HTTP {$r->status()}: ".mb_substr((string) ($r->json('message') ?? $r->body()), 0, 200), $r->status());
        }
        $id = (string) ($r->json('contact.id') ?? '');
        if ($id === '') {
            throw new RuntimeException('Contact upsert returned no contact id.');
        }
        $this->client->addTags($id, self::tags($c)); // additive: never replaces tags set in BrandWorx Marketing
        $fill = ['ghl_contact_id' => $id, 'ghl_synced_at' => now()];
        if (! $c->ghl_contact_id || $c->ghl_contact_id !== $id) {
            $fill['ghl_created_by_erp'] = (bool) $r->json('new', false); // true only when the upsert created the contact
        }
        $c->forceFill($fill)->saveQuietly();

        $this->lastNotes = $notes;

        return $id;
    }

    public function pushEvent(string $event, Customer $c, ?SalesOrder $so, array $extra): string
    {
        $id = $c->ghl_contact_id ?: $this->syncContact($c);
        $tag = self::EVENTS[$event] ?? 'erp-'.Str::slug($event);
        $this->client->removeTags($id, [$tag]);
        $r = $this->client->addTags($id, [$tag]);
        if (! $r->successful()) {
            throw new RuntimeException("Add tag HTTP {$r->status()}", $r->status());
        }
        $note = "tag {$tag}";
        if ($url = MarketingSettings::webhookUrl()) {
            $w = $this->client->webhook($url, ['event' => $event, 'contact_id' => $id, 'customer_code' => $c->code, 'customer' => $c->name,
                'order' => $so?->number, 'total' => $so?->total, 'status' => $so?->status?->value] + $extra);
            if (! $w->successful()) {
                throw new RuntimeException("Inbound webhook HTTP {$w->status()}", $w->status());
            }
            $note .= ' + webhook';
        }

        return $note;
    }
}
