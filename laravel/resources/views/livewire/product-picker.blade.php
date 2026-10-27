@php
    $open = $mode === 'open';
    $uid = 'pp-'.$this->getId();
    $btn = 'fi-btn relative grid-flow-col items-center justify-center rounded-lg bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:ring-white/20';
    $input = 'fi-input block w-full rounded-lg border-none bg-white px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-gray-950/10 dark:bg-white/5 dark:ring-white/20';
    $thumb = function (array $c, string $size = 'h-10 w-10') {
        return $c['image']
            ? '<img src="'.e($c['image']).'" alt="" loading="lazy" class="'.$size.' shrink-0 rounded-md object-cover ring-1 ring-gray-200 dark:ring-gray-700">'
            : '<span class="'.$size.' inline-flex shrink-0 items-center justify-center rounded-md bg-gray-100 text-xs font-semibold text-gray-400 dark:bg-gray-800" aria-hidden="true">'.e(mb_strtoupper(mb_substr($c['name'], 0, 2))).'</span>';
    };
@endphp
<div class="fi-fo-field-wrp" data-test="product-picker" x-data="productPickerScanner('{{ $uid }}')">
    <div class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="text-sm font-medium text-gray-950 dark:text-white">
            {{ $label }}{{ $multi ? 's' : '' }}
            <span class="font-normal text-gray-500 dark:text-gray-400">— search, scan, or browse the catalog</span>
        </div>

        {{-- Search (name / SKU / barcode / vendor item #) --}}
        <div class="relative">
            <input type="search" wire:model.live.debounce.250ms="search" autocomplete="off"
                   placeholder="Search name, SKU, barcode, vendor item #" class="{{ $input }}" data-test="picker-search">
            @if($this->results)
                <ul class="absolute z-30 mt-1 max-h-80 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-900" data-test="picker-results">
                    @foreach($this->results as $r)
                        <li wire:key="res-{{ $r['id'] }}">
                            @if($open)
                                <a href="{{ $r['url'] }}" class="flex items-center gap-3 px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-white/5" data-test="picker-result">
                            @else
                                <button type="button" wire:click="add({{ $r['id'] }})" class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5" data-test="picker-result">
                            @endif
                                {!! $thumb($r) !!}
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate"><strong>{{ $r['sku'] }}</strong> {{ $r['name'] }}</span>
                                    <span class="block text-xs text-gray-500">@if($r['dept']){{ $r['dept'] }} · @endif list ${{ $r['price'] }}@if($showCost) · cost ${{ $r['cost'] }}@endif</span>
                                </span>
                            @if($open)</a>@else</button>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Scan field (USB scanner Enter, or camera on mobile) --}}
        <div class="flex flex-wrap items-end gap-2">
            <div class="min-w-[14rem] flex-1">
                <label class="text-sm font-medium text-gray-950 dark:text-white" for="{{ $uid }}-scan">Scan barcode / UPC / SKU</label>
                <input id="{{ $uid }}-scan" type="text" inputmode="search" autocomplete="off" wire:model="scan"
                       wire:keydown.enter.prevent="applyScan" placeholder="Scan or type, then Enter" class="{{ $input }}" data-test="picker-scan">
            </div>
            <button type="button" wire:click="applyScan"
                    class="fi-btn relative grid-flow-col items-center justify-center rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500">{{ $open ? 'Find' : 'Add' }}</button>
            <button type="button" x-on:click="toggle()" class="{{ $btn }}" data-test="picker-camera"><span x-text="on ? 'Stop camera' : 'Camera'"></span></button>
            <button type="button" wire:click="openBrowse" class="{{ $btn }}" data-test="picker-browse">Browse catalog</button>
        </div>
        @if(session()->has('picker-miss-'.$this->getId()))
            <p class="text-sm text-danger-600" data-test="picker-miss">{{ session('picker-miss-'.$this->getId()) }}</p>
        @endif
        <div id="{{ $uid }}-reader" class="overflow-hidden rounded-lg" style="max-width:320px" wire:ignore></div>

        @unless($open)
            @if($this->picked)
                <ul class="flex flex-wrap gap-2" data-test="picker-picked">
                    @foreach($this->picked as $p)
                        <li class="inline-flex items-center gap-2 rounded-full bg-primary-50 py-1 pl-1 pr-3 text-sm text-primary-800 dark:bg-primary-400/20 dark:text-primary-200" wire:key="picked-{{ $p['id'] }}">
                            {!! $thumb($p, 'h-7 w-7') !!}
                            <span><strong>{{ $p['sku'] }}</strong> {{ \Illuminate\Support\Str::limit($p['name'], 40) }}
                                <span class="text-gray-500 dark:text-gray-400">· list ${{ $p['price'] }}</span></span>
                            <button type="button" wire:click="remove({{ $p['id'] }})" class="font-bold" aria-label="Remove">&times;</button>
                        </li>
                    @endforeach
                    @if($multi && count($this->picked) > 1)
                        <li><button type="button" wire:click="clear" class="text-sm text-gray-500 underline">Clear all</button></li>
                    @endif
                </ul>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">No product selected yet.</p>
            @endif
        @endunless
    </div>

    @if($browsing)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-gray-950/50 p-4 sm:p-8" data-test="picker-browser" wire:click.self="closeBrowse">
            <div class="w-full max-w-4xl rounded-xl bg-white shadow-xl dark:bg-gray-900" wire:click.stop>
                <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 p-4 dark:border-gray-700">
                    <input type="search" wire:model.live.debounce.250ms="browseFilter" placeholder="Filter by name, SKU, brand, vendor item #"
                           class="{{ $input }} min-w-[12rem] flex-1" data-test="picker-browse-filter">
                    @if($multi && ! $open)
                        <button type="button" wire:click="closeBrowse" class="rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500">Use selected ({{ count($this->ids()) }})</button>
                    @endif
                    <button type="button" wire:click="closeBrowse" class="{{ $btn }}">Done</button>
                </div>
                <div class="flex flex-wrap gap-2 border-b border-gray-100 p-3 dark:border-gray-800">
                    <select wire:model.live="browseDepartment" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" data-test="picker-browse-dept">
                        <option value="">All departments</option>
                        @foreach($this->facets['departments'] as $id => $nm)<option value="{{ $id }}">{{ $nm }}</option>@endforeach
                    </select>
                    <select wire:model.live="browseVendor" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" data-test="picker-browse-vendor">
                        <option value="">All vendors</option>
                        @foreach($this->facets['vendors'] as $id => $nm)<option value="{{ $id }}">{{ $nm }}</option>@endforeach
                    </select>
                    <span class="self-center text-sm text-gray-500">{{ $this->browseTotal }} match{{ $this->browseTotal === 1 ? '' : 'es' }}</span>
                </div>
                <div class="max-h-[60vh] overflow-y-auto p-3">
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @forelse($this->tiles as $t)
                            @php $on = in_array($t['id'], $this->ids(), true); @endphp
                            @if($open)
                                <a href="{{ $t['url'] }}" wire:key="tile-{{ $t['id'] }}" class="flex gap-3 rounded-lg border border-gray-200 p-3 text-left text-sm transition hover:border-primary-300 dark:border-gray-700" data-test="picker-tile">
                            @else
                                <button type="button" wire:click="toggle({{ $t['id'] }})" wire:key="tile-{{ $t['id'] }}"
                                        class="flex gap-3 rounded-lg border p-3 text-left text-sm transition {{ $on ? 'border-primary-500 bg-primary-50 dark:bg-primary-400/10' : 'border-gray-200 hover:border-primary-300 dark:border-gray-700' }}" data-test="picker-tile">
                            @endif
                                {!! $thumb($t, 'h-14 w-14') !!}
                                <span class="min-w-0">
                                    <span class="block font-semibold">{{ $t['sku'] }}</span>
                                    <span class="block text-gray-700 dark:text-gray-200">{{ $t['name'] }}</span>
                                    <span class="mt-1 block text-xs text-gray-500">@if($t['dept']){{ $t['dept'] }} · @endif list ${{ $t['price'] }}@if($on) · selected @endif</span>
                                </span>
                            @if($open)</a>@else</button>@endif
                        @empty
                            <p class="col-span-full p-4 text-sm text-gray-500">No products match.</p>
                        @endforelse
                    </div>
                    @if(count($this->tiles) < $this->browseTotal)
                        <div class="mt-3 flex justify-center">
                            <button type="button" wire:click="loadMore" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm" data-test="picker-browse-more">Load more</button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @assets
        <script src="{{ asset('vendor/html5-qrcode/html5-qrcode.min.js') }}"></script>
        <script>
        function productPickerScanner(uid) {
            return {
                s: null, on: false,
                toggle() {
                    if (this.s) { this.s.stop().catch(() => {}); this.s = null; this.on = false; return; }
                    if (typeof Html5Qrcode === 'undefined') { alert('Camera scanner library not loaded. You can still type or use a USB scanner in the scan field.'); return; }
                    this.s = new Html5Qrcode(uid + '-reader');
                    this.on = true;
                    this.s.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 260, height: 120 } }, (text) => {
                        this.$wire.set('scan', text);
                        this.s.stop().catch(() => {}); this.s = null; this.on = false;
                        this.$wire.call('applyScan');
                    }).catch((e) => { alert('Camera not available: ' + e); this.s = null; this.on = false; });
                },
            };
        }
        </script>
    @endassets
</div>
