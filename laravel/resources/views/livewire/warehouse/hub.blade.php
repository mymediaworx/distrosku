<div>
    <div class="card"><a href="{{ route('wh.putaway') }}"><button>Put-away ({{ $putaway }} open)</button></a></div>
    <div class="card"><h3>Receive against a PO</h3>
        <table class="tbl">@forelse($pos as $po)<tr><td>{{ $po->number }}</td><td>{{ $po->vendor->name }}</td><td><span class="pill">{{ $po->status }}</span></td><td>{{ $po->expected_at?->format('M j') }}</td><td>{!! \App\Purchasing\InboundTracking::html($po) !!}</td><td><a href="{{ route('wh.receive', $po) }}">Scan receive</a></td></tr>@empty<tr><td>No open POs.</td></tr>@endforelse</table></div>
    <div class="card"><h3>Pick (confirmed orders)</h3>
        <table class="tbl">@forelse($toPick as $so)<tr><td>{{ $so->number }}</td><td>{{ $so->customer->name }}</td><td><a href="{{ route('wh.pick', $so) }}">Scan pick</a></td><td><a href="{{ route('wh.print.pick', $so) }}" target="_blank">Print pick list</a></td></tr>@empty<tr><td>Nothing to pick.</td></tr>@endforelse</table></div>
    <div class="card"><h3>Counts in progress</h3>
        <table class="tbl">@forelse($counts as $c)<tr><td>{{ $c->number }}</td><td>{{ $c->warehouse->code }} · {{ $c->type }}@if($c->blind) · blind @endif</td><td><a href="{{ route('wh.count', $c) }}">Scan count</a></td></tr>@empty<tr><td>No open counts.</td></tr>@endforelse</table></div>
    <div class="card"><h3>Pack (picked)</h3>
        <table class="tbl">@forelse($toPack as $pl)<tr><td>{{ $pl->number }}</td><td>{{ $pl->salesOrder->number }} · {{ $pl->salesOrder->customer->name }}</td><td><a href="{{ route('wh.pack', $pl->salesOrder) }}">Scan pack</a></td></tr>@empty<tr><td>Nothing to pack.</td></tr>@endforelse</table></div>
</div>
