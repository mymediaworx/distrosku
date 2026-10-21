<x-filament-panels::page>
    @php($d = $this->getData())
    <x-filament::section>
        <div class="text-sm space-y-1">
            @unless($d['enforced'])<p class="font-bold">The KY registry rule is OFF (disabled until CHFS lists UPCs). Nothing is blocked; this screen is for preparing listings only.</p>@endunless
            <p>Source: <a href="{{ $d['url'] }}" target="_blank" rel="noopener" class="underline">KY CHFS Hemp-derived Cannabinoid Registered Products</a>. Copy on file: <b>{{ $d['entries'] }}</b> products (<b>{{ $d['vapes'] }}</b> vapes), last loaded {{ $d['lastSeen'] ? \Illuminate\Support\Carbon::parse($d['lastSeen'])->toDateString() : 'never' }}.</p>
            <p>Rule <i>KY: THC vape must be on the CHFS hemp product registry</i> (Compliance &gt; Eligibility rules) blocks order confirm into KY for a THC vape with no current KY listing. THC vape = vape form (Form "Vape", Subtype "Vape" or tax class Vapor) <b>and</b> a cannabinoid (Subtype Hemp/CBD, hemp type set, cannabinoid flag, synthetic, or any THC mg).</p>
            <p>Matching: the registry has no SKU or UPC, so a SKU is listed automatically only when its "brand + name" (or name) equals the registry's after removing case and punctuation. Close matches are suggestions; link them or mark listed by hand.</p>
            <p><b>{{ $d['listed'] }}</b> of <b>{{ $d['total'] }}</b> THC vapes are listed today.</p>
        </div>
    </x-filament::section>
    <div class="text-sm"><label>Show <select wire:model.live="filter" class="rounded border-gray-300 dark:bg-gray-900"><option value="unlisted">Not listed</option><option value="all">All THC vapes</option></select></label></div>
    <x-filament::section heading="THC vapes">
        @if($d['rows']->isNotEmpty())
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <thead><tr class="text-left border-b"><th class="p-1">SKU</th><th class="p-1">Brand</th><th class="p-1">Name</th><th class="p-1">KY listing</th><th class="p-1">Registry suggestions</th><th class="p-1"></th></tr></thead>
            <tbody>@foreach($d['rows'] as $r)<tr class="border-b align-top">
                <td class="p-1">{{ $r['product']->sku }}</td><td class="p-1">{{ $r['product']->brand }}</td><td class="p-1">{{ $r['product']->name }}</td>
                <td class="p-1">@if($r['listing']) Listed from {{ $r['listing']->listed_from->toDateString() }} ({{ $r['listing']->manual ? 'manual' : 'auto' }}{{ $r['listing']->listing_ref ? ', '.$r['listing']->listing_ref : '' }}) @else @if($d['enforced'])<span class="text-danger-600 font-bold">Not listed: blocked into KY</span>@else<span class="font-bold">Not listed (rule off, not blocked)</span>@endif @endif</td>
                <td class="p-1">@foreach($r['suggest'] as $s)<div>{{ $s['entry']->label() }} <span class="text-gray-500">{{ $s['score'] }}%</span>
                    @if($this::canManage())<button type="button" class="underline text-primary-600" wire:click="linkTo({{ $r['product']->id }}, {{ $s['entry']->id }})" wire:confirm="Link {{ $r['product']->sku }} to registry #{{ $s['entry']->record_id }}?">Link</button>@endif</div>@endforeach</td>
                <td class="p-1">@if($this::canManage())<button type="button" class="underline text-primary-600" wire:click="markListed({{ $r['product']->id }})">Listed / not listed…</button>@endif</td>
            </tr>@endforeach</tbody></table></div>
        @else
            <p class="text-sm">{{ $this->filter === 'all' ? 'No THC vapes in the catalog.' : 'Every THC vape is listed.' }}</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
