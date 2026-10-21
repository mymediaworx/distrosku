<div>
    <div class="card">
        <h3>Account balance: ${{ number_format((float) $aging['total'], 2) }}</h3>
        @if((float) $opening != 0)<p class="muted">Includes beginning balance ${{ number_format((float) $opening, 2) }}.</p>@endif
        <table><tr>@foreach(['0-30' => 'Current (0-30)', '31-60' => '31-60', '61-90' => '61-90', '90+' => 'Over 90'] as $k => $t)<th class="r">{{ $t }}</th>@endforeach</tr>
            <tr>@foreach(['0-30', '31-60', '61-90', '90+'] as $k)<td class="r">${{ number_format((float) $aging[$k], 2) }}</td>@endforeach</tr></table>
        <div class="inline" style="margin-top:.6rem">
            <a href="{{ route('portal.statement') }}"><button type="button" class="sec">Statement PDF</button></a>
            @if((float) $aging['total'] > 0)<a href="{{ route('portal.pay') }}"><button type="button">Pay balance</button></a>@endif
        </div>
    </div>
    @if($skipped)<div class="card err"><strong>Some items could not be re-ordered:</strong>@foreach($skipped as $s)<div>{{ $s }}</div>@endforeach
        <a href="{{ route('portal.catalog', ['cart' => 1]) }}">Go to cart</a></div>@endif
    <div class="card">
        <h3>Orders & invoices</h3>
        <table>
            <tr><th>Order</th><th>Date</th><th>Status</th><th class="r">Total</th><th class="r">Open</th><th></th></tr>
            @forelse($orders as $o)
                <tr wire:key="o{{ $o->id }}">
                    <td><a href="#" wire:click.prevent="toggle({{ $o->id }})">{{ $o->number }}</a></td>
                    <td>{{ $o->order_date?->format('m/d/Y') }}</td>
                    <td><span class="badge">{{ $o->status->value }}</span></td>
                    <td class="r">${{ number_format((float) $o->total, 2) }}</td>
                    <td class="r">{{ $o->status->value === 'invoiced' ? '$'.number_format((float) $o->balance(), 2) : '' }}</td>
                    <td class="r">
                        <button type="button" class="sec" style="width:auto;padding:.35rem .6rem" wire:click="reorder({{ $o->id }})">Reorder</button>
                        @if($o->status->value === 'invoiced')
                            <a href="{{ route('portal.invoice', $o) }}">PDF</a>
                            @if((float) $o->balance() > 0) · <a href="{{ route('portal.pay', ['invoice' => $o->id]) }}">Pay</a>@endif
                        @endif
                    </td>
                </tr>
                @if($openId === $o->id)
                    <tr><td colspan="6">@foreach($o->lines as $l)<div>{{ (float) $l->qty }} × {{ $l->product?->sku }} {{ $l->product?->name }} @ ${{ number_format((float) $l->unit_price, 2) }}</div>@endforeach
                        <span class="muted">{{ $o->isPickup() ? 'Counter pickup' : 'Ship to '.$o->shipTo?->name }}</span></td></tr>
                @endif
            @empty
                <tr><td colspan="6">No orders yet.</td></tr>
            @endforelse
        </table>
        {{ $orders->links() }}
    </div>
</div>
