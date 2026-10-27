@php
    $multi = (bool) ($this->productLookupMulti ?? false);
@endphp
<div class="fi-fo-field-wrp" data-test="special-price-product-lookup" wire:ignore.self>
    <div class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="text-sm font-medium text-gray-950 dark:text-white">
            Product{{ $multi ? 's' : '' }}
            <span class="font-normal text-gray-500 dark:text-gray-400">— search, scan, or browse the catalog</span>
        </div>

        {{-- (b) Barcode / SKU scan field (POS-style Enter); camera on mobile via html5-qrcode (same asset as Quick Tools) --}}
        <div class="flex flex-wrap items-end gap-2" x-data="specialPriceScanner()">
            <div class="min-w-[14rem] flex-1">
                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3" for="special-price-scan">
                    <span class="text-sm font-medium text-gray-950 dark:text-white">Scan barcode / UPC / SKU</span>
                </label>
                <input id="special-price-scan" type="text" inputmode="search" autocomplete="off"
                       wire:model="productScan"
                       wire:keydown.enter.prevent="applyProductScan"
                       placeholder="Scan or type, then Enter"
                       class="fi-input block w-full rounded-lg border-none bg-white px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-gray-950/10 dark:bg-white/5 dark:ring-white/20"
                       data-test="special-price-scan">
            </div>
            <button type="button" wire:click="applyProductScan"
                    class="fi-btn fi-btn-size-md relative grid-flow-col items-center justify-center rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500">
                Add
            </button>
            <button type="button" x-on:click="toggle()"
                    class="fi-btn relative grid-flow-col items-center justify-center rounded-lg bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:ring-white/20"
                    data-test="special-price-camera">
                <span x-text="on ? 'Stop camera' : 'Camera'"></span>
            </button>
            <button type="button" wire:click="openProductBrowse"
                    class="fi-btn relative grid-flow-col items-center justify-center rounded-lg bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:ring-white/20"
                    data-test="special-price-browse">
                Browse catalog
            </button>
        </div>
        <div id="special-price-reader" class="overflow-hidden rounded-lg" style="max-width:320px"></div>

        {{-- Selected chips --}}
        @if($this->pickedProducts)
            <ul class="flex flex-wrap gap-2" data-test="special-price-picked">
                @foreach($this->pickedProducts as $p)
                    <li class="inline-flex items-center gap-2 rounded-full bg-primary-50 px-3 py-1 text-sm text-primary-800 dark:bg-primary-400/20 dark:text-primary-200"
                        wire:key="picked-{{ $p['id'] }}">
                        <span><strong>{{ $p['sku'] }}</strong> {{ \Illuminate\Support\Str::limit($p['name'], 40) }}
                            <span class="text-gray-500 dark:text-gray-400">· list ${{ $p['price'] }}</span></span>
                        <button type="button" wire:click="removePickedProduct({{ $p['id'] }})" class="font-bold" aria-label="Remove">&times;</button>
                    </li>
                @endforeach
                @if($multi && count($this->pickedProducts) > 1)
                    <li><button type="button" wire:click="clearPickedProducts" class="text-sm text-gray-500 underline">Clear all</button></li>
                @endif
            </ul>
            @if($multi && count($this->pickedProducts) > 1)
                <p class="text-sm text-gray-600 dark:text-gray-300" data-test="special-price-multi-hint">
                    The same special price will be created for {{ count($this->pickedProducts) }} products.
                </p>
            @endif
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">No product selected yet.</p>
        @endif
    </div>

    {{-- (c) Browse catalog modal --}}
    @if($this->productBrowsing)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-gray-950/50 p-4 sm:p-8" data-test="special-price-browser" wire:click.self="closeProductBrowse">
            <div class="w-full max-w-4xl rounded-xl bg-white shadow-xl dark:bg-gray-900" wire:click.stop>
                <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 p-4 dark:border-gray-700">
                    <input type="search" wire:model.live.debounce.250ms="browseFilter"
                           placeholder="Filter by name, SKU, brand, vendor item #"
                           class="fi-input min-w-[12rem] flex-1 rounded-lg border-none bg-white px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-gray-950/10 dark:bg-white/5 dark:ring-white/20"
                           data-test="special-price-browse-filter">
                    @if($multi)
                        <button type="button" wire:click="confirmBrowseSelection"
                                class="rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500">
                            Use selected ({{ count($this->pickedProductIds) }})
                        </button>
                    @endif
                    <button type="button" wire:click="closeProductBrowse"
                            class="rounded-lg bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 dark:bg-white/5 dark:text-white dark:ring-white/20">
                        Done
                    </button>
                </div>
                <div class="flex flex-wrap gap-2 border-b border-gray-100 p-3 dark:border-gray-800">
                    <select wire:model.live="browseDepartment"
                            class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
                            data-test="special-price-browse-dept">
                        <option value="">All departments</option>
                        @foreach($this->browseFacets['departments'] as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="browseVendor"
                            class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
                            data-test="special-price-browse-vendor">
                        <option value="">All vendors</option>
                        @foreach($this->browseFacets['vendors'] as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    <span class="self-center text-sm text-gray-500">{{ $this->browseTotal }} match{{ $this->browseTotal === 1 ? '' : 'es' }}</span>
                </div>
                <div class="max-h-[60vh] overflow-y-auto p-3">
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @forelse($this->browseTiles as $t)
                            @php $on = in_array($t['id'], $this->pickedProductIds, true); @endphp
                            <button type="button"
                                    wire:click="{{ $multi ? 'toggleBrowseProduct' : 'selectBrowseProduct' }}({{ $t['id'] }})"
                                    wire:key="tile-{{ $t['id'] }}"
                                    class="rounded-lg border p-3 text-left text-sm transition {{ $on ? 'border-primary-500 bg-primary-50 dark:bg-primary-400/10' : 'border-gray-200 hover:border-primary-300 dark:border-gray-700' }}"
                                    data-test="special-price-tile">
                                <div class="font-semibold">{{ $t['sku'] }}</div>
                                <div class="text-gray-700 dark:text-gray-200">{{ $t['name'] }}</div>
                                <div class="mt-1 text-xs text-gray-500">
                                    @if($t['dept']){{ $t['dept'] }} · @endif list ${{ $t['price'] }}
                                    @if($on) · selected @endif
                                </div>
                            </button>
                        @empty
                            <p class="col-span-full p-4 text-sm text-gray-500">No products match.</p>
                        @endforelse
                    </div>
                    @if(count($this->browseTiles) < $this->browseTotal)
                        <div class="mt-3 flex justify-center">
                            <button type="button" wire:click="loadMoreBrowse" wire:loading.attr="disabled"
                                    class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm"
                                    data-test="special-price-browse-more">
                                Load more
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>

@once
    <script src="{{ asset('vendor/html5-qrcode/html5-qrcode.min.js') }}"></script>
@endonce
<script>
function specialPriceScanner() {
    return {
        s: null,
        on: false,
        toggle() {
            if (this.s) {
                this.s.stop().catch(() => {});
                this.s = null;
                this.on = false;
                return;
            }
            if (typeof Html5Qrcode === 'undefined') {
                alert('Camera scanner library not loaded. You can still type or use a USB/wedge scanner in the scan field.');
                return;
            }
            this.s = new Html5Qrcode('special-price-reader');
            this.on = true;
            this.s.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 260, height: 120 } }, (text) => {
                this.$wire.set('productScan', text);
                this.s.stop().catch(() => {});
                this.s = null;
                this.on = false;
                this.$wire.call('applyProductScan');
            }).catch((e) => {
                alert('Camera not available: ' + e);
                this.s = null;
                this.on = false;
            });
        },
    };
}
</script>
