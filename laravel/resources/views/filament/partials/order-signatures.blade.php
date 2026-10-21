@foreach($so->signatures as $sg)
<div data-test="order-signature" style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-start;padding:.5rem 0;border-bottom:1px solid rgba(0,0,0,.08)">
    @if($img = $sg->dataUri())<img src="{{ $img }}" alt="Signature" style="max-height:90px;max-width:260px;background:#fff;border:1px solid #ddd;border-radius:6px">@endif
    <div style="flex:1;min-width:220px;font-size:.875rem">
        <strong>{{ $sg->signer_name }}</strong> · {{ ucfirst($sg->context) }} · {{ $sg->signed_at->timezone(config('app.timezone'))->format('M j, Y g:i A T') }}<br>
        @foreach($sg->acknowledgments as $a)&#10003; {{ $a }}<br>@endforeach
        <span style="color:#6b7280">Staff: {{ $sg->user?->name ?? '-' }} @if($sg->ip)· IP {{ $sg->ip }}@endif @if($sg->latitude)· GPS {{ $sg->latitude }}, {{ $sg->longitude }}@endif</span>
    </div>
</div>
@endforeach
<p style="margin-top:.5rem"><a href="{{ route('docs.delivery-proof', $so) }}" style="text-decoration:underline">Delivery receipt PDF</a></p>
