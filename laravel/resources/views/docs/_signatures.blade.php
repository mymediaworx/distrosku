{{-- Phase 15 signature block for printables (no DistroSKU footer on printables). --}}
@foreach($so->signatures as $sg)
<table style="margin-top:12px" class="box"><tr><td style="border:0;width:60%">
    <strong>Received / acknowledged ({{ $sg->context }})</strong><br>
    @foreach($sg->acknowledgments as $a)&#9745; {{ $a }}<br>@endforeach
    Printed name: <strong>{{ $sg->signer_name }}</strong><br>
    <span class="muted">Signed {{ $sg->signed_at->timezone(config('app.timezone'))->format('m/d/Y g:i A T') }} · by staff {{ $sg->user?->name ?? '-' }}@if($sg->ip) · IP {{ $sg->ip }}@endif @if($sg->latitude) · GPS {{ $sg->latitude }}, {{ $sg->longitude }}@endif</span></td>
    <td style="border:0" class="r">@if($img = $sg->dataUri())<img src="{{ $img }}" style="max-height:80px;max-width:240px;border-bottom:1px solid #111">@endif</td></tr></table>
@endforeach
