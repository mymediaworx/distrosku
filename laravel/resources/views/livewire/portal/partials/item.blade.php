@php($p = $r['product'])
<div class="item" wire:key="{{ $k }}{{ $p->id }}">
    @if($p->imageUrl())<img class="thumb" src="{{ $p->imageUrl() }}" alt="" loading="lazy">@else<div class="thumb"></div>@endif
    <div>
        <strong>{{ $p->name }}</strong><br><span class="muted">{{ $p->sku }}@if($p->upc) · UPC {{ $p->upc }}@endif</span><br>
        <span class="price">${{ number_format((float) $r['price'], 2) }}</span> <span class="muted">/ {{ $p->unit }}</span>
        @if($r['reasons'])
            @foreach($r['reasons'] as $why)<div class="na" data-test="unavailable">Unavailable: {{ $why }}</div>@endforeach
        @else
            <div class="inline" style="margin-top:.4rem"><button type="button" wire:click="add({{ $p->id }})">Add</button>
                @if(isset($cart[$p->id]))<span class="badge">{{ (int) $cart[$p->id] }} in cart</span>@endif</div>
        @endif
    </div>
</div>
