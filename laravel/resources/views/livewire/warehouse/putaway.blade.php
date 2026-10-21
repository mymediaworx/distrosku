<div>
    @if($taskId)
        <div class="card"><h3>Scan bin, then the item</h3>
            <label>Bin</label><input wire:model="bin" placeholder="Scan bin label" data-test="bin">
            @include('livewire.warehouse.scanbox', ['action' => 'add', 'placeholder' => 'Scan item'])
            <button class="btn2" wire:click="$set('taskId', null)" style="margin-top:.5rem">Back</button>
        </div>
    @else
        @if($ok)<p class="ok" data-test="ok">{{ $ok }}</p>@endif
        @if($err)<p class="err">{{ $err }}</p>@endif
    @endif
    <div class="card"><h3>Open put-away tasks</h3><table class="tbl"><tr><th>SKU</th><th>Item</th><th>Qty</th><th>WH</th><th>Suggested bin</th><th></th></tr>
        @forelse($tasks as $t)<tr><td>{{ $t->product->sku }}</td><td>{{ $t->product->name }}</td><td>{{ (float) $t->qty }}</td><td>{{ $t->warehouse->code }}</td><td>{{ $t->suggestedBin?->code ?? '—' }}</td><td><button wire:click="choose({{ $t->id }})">Put away</button></td></tr>
        @empty<tr><td colspan="6">Nothing to put away.</td></tr>@endforelse
    </table></div>
</div>
