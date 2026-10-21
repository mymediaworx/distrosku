<form wire:submit="{{ $action }}" class="scan" x-data x-init="$refs.s.focus()" @scanned.window="$nextTick(() => $refs.s.focus())">
    <input x-ref="s" wire:model="scan" placeholder="{{ $placeholder ?? 'Scan barcode or type SKU' }}" autocomplete="off" autofocus inputmode="text" data-test="scan">
    <input wire:model="qty" style="width:80px" inputmode="decimal" aria-label="Qty">
    <button type="submit">OK</button>
</form>
@if($ok)<p class="ok" data-test="ok">{{ $ok }}</p>@endif
@if($err)<p class="err" data-test="err">{{ $err }}</p>@endif
