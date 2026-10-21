<div>
    <div class="card"><strong>Pickup · {{ $so->number }}</strong> <span class="pill">{{ $so->status->value }}</span><div class="muted">{{ $so->customer->name }} · {{ $so->customer->terms }} · ${{ number_format((float) $so->total, 2) }}</div>
        <table class="tbl">@foreach($so->lines as $l)<tr><td>{{ $l->product->name }}</td><td>{{ (float) $l->qty }}</td></tr>@endforeach</table></div>
    @if(in_array($so->status->value, ['confirmed', 'picked'], true))
        @if($needsSig)<div class="card">@include('partials.signature-pad', ['acks' => $acks])</div>@endif
        @if($err)<div class="card err" data-test="err">{{ $err }}</div>@endif
        <button wire:click="complete" data-test="complete">Complete pickup</button>
    @else
        @if($ok)<div class="card ok" data-test="ok">{{ $ok }}</div>@endif
        <p><a class="btn btn2" href="{{ route('docs.delivery-proof', $so) }}" target="_blank">Delivery receipt PDF</a></p>
    @endif
</div>
