<?php

namespace App\Marketing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** 0.19: thin client for the Marketing (HighLevel) API v2. The token is only ever sent as a header. */
class GhlClient
{
    private function http(): PendingRequest
    {
        $t = MarketingSettings::token();
        if (! $t) {
            throw new RuntimeException('Marketing token is not set.');
        }

        return Http::baseUrl(MarketingSettings::API)->withToken($t)->acceptJson()->timeout(20)
            ->withHeaders(['Version' => MarketingSettings::API_VERSION]);
    }

    private function loc(): string
    {
        return (string) MarketingSettings::locationId() ?: throw new RuntimeException('Marketing Location ID is not set.');
    }

    /**
     * Read-only probe of what the token can reach. @return array<string, array{ok: bool, status: int, note: string}>
     */
    public function test(): array
    {
        $l = $this->loc();
        $probes = [
            'location' => "/locations/{$l}",
            'contacts' => "/contacts/?locationId={$l}&limit=1",
            'custom_fields' => "/locations/{$l}/customFields",
            'tags' => "/locations/{$l}/tags",
            'pipelines' => "/opportunities/pipelines?locationId={$l}",
            'workflows' => "/workflows/?locationId={$l}",
            'conversations' => "/conversations/search?locationId={$l}&limit=1",
            'users' => "/users/?locationId={$l}",
            'phone_numbers' => "/phone-system/numbers/location/{$l}",
            'opportunities' => "/opportunities/search?location_id={$l}&limit=1",
            'products' => "/products/?locationId={$l}&limit=1",
            'invoices' => "/invoices/?altId={$l}&altType=location&limit=1&offset=0",
        ];
        $out = [];
        foreach ($probes as $k => $path) {
            try {
                $r = $this->http()->get($path);
                $note = $r->successful() ? ($k === 'location' ? (string) ($r->json('location.name') ?? 'ok') : 'ok') : mb_substr((string) ($r->json('message') ?? $r->body()), 0, 120);
                $out[$k] = ['ok' => $r->successful(), 'status' => $r->status(), 'note' => is_array($note) ? implode(' ', $note) : $note];
            } catch (\Throwable $e) {
                $out[$k] = ['ok' => false, 'status' => 0, 'note' => mb_substr($e->getMessage(), 0, 120)];
            }
        }

        return $out;
    }

    /** Read-only GET (custom field lookup). */
    public function get(string $path, array $query = []): Response
    {
        return $this->http()->get($path, $query);
    }

    public function locationId(): string
    {
        return $this->loc();
    }

    /** Create or update a contact (matched by email/phone per the location's duplicate settings). */
    public function upsertContact(array $payload): Response
    {
        return $this->http()->post('/contacts/upsert', $payload + ['locationId' => $this->loc()]);
    }

    public function addTags(string $contactId, array $tags): Response
    {
        return $this->http()->post("/contacts/{$contactId}/tags", ['tags' => array_values($tags)]);
    }

    public function removeTags(string $contactId, array $tags): Response
    {
        return $this->http()->delete("/contacts/{$contactId}/tags", ['tags' => array_values($tags)]);
    }

    /** Inbound webhook (a workflow trigger URL set in Marketing). No auth header goes to it. */
    public function webhook(string $url, array $payload): Response
    {
        return Http::timeout(20)->acceptJson()->post($url, $payload);
    }

    /** Transactional send. Only called by GhlNotifier when sending is switched on. */
    public function sendMessage(array $payload): Response
    {
        return $this->http()->post('/conversations/messages', $payload);
    }
}
