<div>
@if($company->suspended)
    <div class="card" data-test="suspended"><h2>Account suspended</h2><p>This quick-tools link is disabled because the {{ $company->name }} account is suspended. Contact your manager or BrandWorx support.</p></div>
@else
    <div class="tabs"><button type="button" class="{{ $tab === 'create' ? 'on' : '' }}" wire:click="$set('tab','create')">Create SKU</button>
        <button type="button" class="{{ $tab === 'lookup' ? 'on' : '' }}" wire:click="$set('tab','lookup')">Lookup</button>
        <button type="button" class="{{ $tab === 'count' ? 'on' : '' }}" wire:click="$set('tab','count')" data-test="tab-count">Count</button></div>
    @error('rate')<p class="err">{{ $message }}</p>@enderror

    @if($tab === 'create')
    <form class="card" wire:submit="create">
        <label for="d">Department</label>
        <select id="d" wire:model="department_id" required><option value="">Choose…</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }} ({{ $d->code }})</option>@endforeach</select>
        @error('department_id')<p class="err">{{ $message }}</p>@enderror
        <label for="n">Name</label><input id="n" wire:model="name" maxlength="255" required>@error('name')<p class="err">{{ $message }}</p>@enderror
        <label for="p">Price</label><input id="p" wire:model="price" inputmode="decimal" required>@error('price')<p class="err">{{ $message }}</p>@enderror
        <label for="u">UPC</label>
        <div class="inline" x-data="scanner('upc')">
            {{-- keyboard-wedge scanners type the code then Enter: keep focus here, do not submit the form --}}
            <input id="u" wire:model="upc" inputmode="numeric" autocomplete="off" x-ref="input" x-on:keydown.enter.prevent="$refs.input.blur(); $nextTick(() => document.getElementById('n').focus())">
            <button type="button" class="sec" x-on:click="toggle()">Scan</button>
        </div>
        <div id="reader-upc" wire:ignore></div>
        @error('upc')<p class="err">{{ $message }}</p>@enderror
        <label for="i">Image (JPG/PNG/WebP/GIF, max 5 MB)</label><input id="i" type="file" wire:model="image" accept="image/jpeg,image/png,image/webp,image/gif" capture="environment">
        <div wire:loading wire:target="image">Uploading…</div>@error('image')<p class="err">{{ $message }}</p>@enderror
        <p><button type="submit" wire:loading.attr="disabled">Create SKU</button></p>
    </form>
    @if($created)
        <div class="card ok" data-test="created">Created <strong>{{ $created['sku'] }}</strong> {{ $created['name'] }}.
            <a href="{{ $this->labelUrl([$created['id']]) }}" target="_blank">Print label</a></div>
    @endif
    @elseif($tab === 'count')
    <form class="card" wire:submit="countSearch" data-test="count-search">
        <label for="cq">Item to count: SKU, name or UPC</label>
        <div class="inline" x-data="scanner('cq')">
            <input id="cq" wire:model="cq" autocomplete="off" x-ref="input" enterkeyhint="search">
            <button type="button" class="sec" x-on:click="toggle()">Scan</button><button type="submit">Find</button>
        </div>
        <div id="reader-cq" wire:ignore></div>
        @error('cq')<p class="err">{{ $message }}</p>@enderror
    </form>
    @if($countResults)
        <div class="card">
            @foreach($countResults as $r)
                <div class="res" wire:key="cr-{{ $r['id'] }}">@if($r['img'])<img class="thumb" src="{{ $r['img'] }}" alt="">@else<div class="thumb"></div>@endif
                    <div><strong>{{ $r['sku'] }}</strong> {{ $r['name'] }}<br><small>{{ $r['dept'] }}@if($r['upc']) · UPC {{ $r['upc'] }}@endif</small></div>
                    <button type="button" class="{{ $count_product_id === $r['id'] ? '' : 'sec' }}" wire:click="pickCount({{ $r['id'] }})">{{ $count_product_id === $r['id'] ? 'Counting' : 'Count this' }}</button></div>
            @endforeach
        </div>
    @endif
    @if($count_product_id)
    <form class="card" wire:submit="submitCount" data-test="count-form">
        <label for="cw">Where</label>
        <select id="cw" wire:model="count_warehouse_id"><option value="">Choose…</option>@foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }} ({{ $w->code }})</option>@endforeach</select>
        @error('count_warehouse_id')<p class="err">{{ $message }}</p>@enderror
        <label for="cn">How many are there</label><input id="cn" wire:model="count_qty" inputmode="decimal" required>@error('count_qty')<p class="err">{{ $message }}</p>@enderror
        <label for="cb">Your name</label><input id="cb" wire:model="count_by" maxlength="100" required>@error('count_by')<p class="err">{{ $message }}</p>@enderror
        <label for="cm">Note (optional)</label><input id="cm" wire:model="count_note" maxlength="255">
        <p><button type="submit" wire:loading.attr="disabled">Save count</button></p>
        <small>This does not change stock. A manager reviews it first.</small>
    </form>
    @endif
    @if($countDone)<div class="card ok" data-test="count-done">{{ $countDone }}</div>@endif
    @else
    <form class="card" wire:submit="search">
        <label for="q">SKU code, name or UPC</label>
        <div class="inline" x-data="scanner('q')">
            <input id="q" wire:model="q" autocomplete="off" x-ref="input" enterkeyhint="search">
            <button type="button" class="sec" x-on:click="toggle()">Scan</button><button type="submit">Find</button>
        </div>
        <div id="reader-q" wire:ignore></div>
    </form>
    @if($results)
        <div class="card">
            @foreach($results as $r)
                <div class="res">@if($r['img'])<img class="thumb" src="{{ $r['img'] }}" alt="">@else<div class="thumb"></div>@endif
                    <div><strong>{{ $r['sku'] }}</strong> {{ $r['name'] }}<br><small>{{ $r['dept'] }} · ${{ $r['price'] }}@if($r['upc']) · UPC {{ $r['upc'] }}@endif</small></div>
                    <a href="{{ $this->labelUrl([$r['id']]) }}" target="_blank">Label</a></div>
            @endforeach
            <p><a href="{{ $this->labelUrl(array_column($results, 'id')) }}" target="_blank">Print all labels</a></p>
        </div>
    @elseif($q !== '')<p>No matches.</p>@endif
    @endif
@endif
<script>
function scanner(field) {
    return {
        s: null,
        toggle() {
            if (this.s) { this.s.stop().catch(() => {}); this.s = null; return; }
            if (typeof Html5Qrcode === 'undefined') { alert('Scanner library not loaded'); return; }
            this.s = new Html5Qrcode('reader-' + field);
            this.s.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 260, height: 120 } }, (text) => {
                this.$wire.set(field, text); this.s.stop().catch(() => {}); this.s = null;
                if (field === 'q') this.$wire.search();
                if (field === 'cq') this.$wire.countSearch();
            }).catch((e) => { alert('Camera not available: ' + e); this.s = null; });
        },
    };
}
</script>
</div>
