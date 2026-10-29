<div>
    {{-- One card per document with full-size buttons: these screens are used one-handed on handhelds, often with gloves. --}}
    <div class="card"><a class="btn wide" href="{{ route('wh.putaway') }}" data-test="putaway">Put-away ({{ $putaway }} open)</a></div>

    <section class="card"><h2>Receive against a PO</h2>
        @forelse($pos as $po)
            <div class="item" data-test="hub-po">
                <div class="meta"><strong>{{ $po->number }}</strong> <span class="pill">{{ $po->status }}</span>
                    <span class="sub">{{ $po->vendor->name }}</span>
                    @php($track = \App\Purchasing\InboundTracking::html($po))
                    @if($po->expected_at || $track)<span class="sub small">@if($po->expected_at)Expected {{ $po->expected_at->format('M j') }}@endif @if($po->expected_at && $track) · @endif{!! $track !!}</span>@endif
                </div>
                <div class="acts"><a class="btn" href="{{ route('wh.receive', $po) }}">Scan receive</a></div>
            </div>
        @empty
            <p class="none">No open POs.</p>
        @endforelse
    </section>

    <section class="card"><h2>Pick (confirmed orders)</h2>
        @forelse($toPick as $so)
            <div class="item" data-test="hub-pick">
                <div class="meta"><strong>{{ $so->number }}</strong><span class="sub">{{ $so->customer->name }}</span></div>
                <div class="acts"><a class="btn" href="{{ route('wh.pick', $so) }}">Scan pick</a>
                    <a class="btn sec" href="{{ route('wh.print.pick', $so) }}" target="_blank">Print pick list</a></div>
            </div>
        @empty
            <p class="none">Nothing to pick.</p>
        @endforelse
    </section>

    @if($tasks->isNotEmpty() || (\App\Warehouse\CycleCountPlanner::enabled() && auth()->user()->canModule('counts', 'manage')))
    <section class="card" data-test="cycle-tasks"><h2>Today's cycle counts</h2>
        @if($ok)<p class="ok" data-test="ok">{{ $ok }}</p>@endif @if($err)<p class="err">{{ $err }}</p>@endif
        @forelse($tasks as $t)
            <div class="item" data-test="cycle-task">
                <div class="meta"><strong>{{ $t->product->sku }}</strong> <span class="pill">{{ $t->status }}</span>
                    <span class="sub">{{ $t->product->name }} · {{ $t->warehouse->code }}</span>
                    <span class="sub small">{{ $t->reasonLabel() }}@if($t->abc_class) · class {{ $t->abc_class }}@endif @if($t->assignee) · {{ $t->assignee->name }}@endif</span></div>
                <div class="acts">
                    @if($t->status === 'open')
                        <button class="btn" wire:click="startTasks({{ $t->warehouse_id }}, {{ $t->id }})">Count</button>
                        @if($t->assigned_to !== auth()->id())<button class="btn sec" wire:click="takeTask({{ $t->id }})">Take</button>
                        @else<button class="btn sec" wire:click="releaseTask({{ $t->id }})">Release</button>@endif
                        <button class="btn sec" wire:click="skipTask({{ $t->id }})">Skip</button>
                    @elseif($t->status === 'counting' && $t->inventoryCount)
                        <a class="btn" href="{{ route('wh.count', $t->inventoryCount) }}">Continue {{ $t->inventoryCount->number }}</a>
                    @endif
                </div>
            </div>
        @empty
            <p class="none">No cycle count tasks today.</p>
        @endforelse
        @foreach($tasks->where('status', 'open')->groupBy('warehouse_id') as $wid => $group)
            @if($group->count() > 1)<div class="acts" style="margin-top:.5rem"><button class="btn wide" wire:click="startTasks({{ $wid }})" data-test="start-all">Count all {{ $group->count() }} open tasks ({{ $group->first()->warehouse->code }})</button></div>@endif
        @endforeach
    </section>
    @endif

    <section class="card"><h2>Counts in progress</h2>
        @forelse($counts as $c)
            <div class="item">
                <div class="meta"><strong>{{ $c->number }}</strong><span class="sub">{{ $c->warehouse->code }} · {{ $c->type }}@if($c->blind) · blind @endif</span></div>
                <div class="acts"><a class="btn" href="{{ route('wh.count', $c) }}">Scan count</a></div>
            </div>
        @empty
            <p class="none">No open counts.</p>
        @endforelse
    </section>

    <section class="card"><h2>Pack (picked)</h2>
        @forelse($toPack as $pl)
            <div class="item">
                <div class="meta"><strong>{{ $pl->number }}</strong><span class="sub">{{ $pl->salesOrder->number }} · {{ $pl->salesOrder->customer->name }}</span></div>
                <div class="acts"><a class="btn" href="{{ route('wh.pack', $pl->salesOrder) }}">Scan pack</a></div>
            </div>
        @empty
            <p class="none">Nothing to pack.</p>
        @endforelse
    </section>
</div>
