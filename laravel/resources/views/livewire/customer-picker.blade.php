@php
    $uid = 'cp-'.$this->getId();
    $box = 'width:100%;box-sizing:border-box;padding:.5rem .6rem;border:1px solid #9ca3af;border-radius:.5rem;font-size:inherit;background:transparent;color:inherit';
    $sel = $this->selected;
@endphp
<div data-test="customer-picker" style="position:relative;width:100%">
    @if($label !== '')<label for="{{ $uid }}" style="display:block;font-weight:600;margin-bottom:.25rem">{{ $label }}</label>@endif
    @if($sel)
        <div data-test="customer-selected" style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;{{ $box }}">
            <span style="min-width:0">
                <strong>{{ $sel['code'] }}</strong> — {{ $sel['name'] }}@if($sel['dba']) <span style="opacity:.75">(DBA {{ $sel['dba'] }})</span>@endif
                <span style="display:block;font-size:.8em;opacity:.7">{{ collect([$sel['city'], $sel['phone']])->filter()->implode(' · ') }}</span>
            </span>
            @if($clearable)<button type="button" wire:click="clear" data-test="customer-clear" style="border:1px solid #9ca3af;border-radius:.4rem;padding:.2rem .6rem;background:transparent;color:inherit;cursor:pointer">Change</button>@endif
        </div>
    @else
        <div style="display:flex;gap:.5rem;align-items:center">
            <input id="{{ $uid }}" type="search" wire:model.live.debounce.250ms="search" autocomplete="off" placeholder="{{ $placeholder }}" data-test="customer-search" style="{{ $box }}">
            @if($walkIn)<button type="button" wire:click="walkIn" data-test="customer-walkin" style="white-space:nowrap;border:1px solid #9ca3af;border-radius:.5rem;padding:.5rem .7rem;background:transparent;color:inherit;cursor:pointer">Walk-in (CASH)</button>@endif
        </div>
        @if(trim($search) !== '')
            <ul data-test="customer-results" style="position:absolute;z-index:40;left:0;right:0;margin:.25rem 0 0;padding:0;list-style:none;max-height:20rem;overflow-y:auto;background:#fff;color:#111827;border:1px solid #d1d5db;border-radius:.5rem;box-shadow:0 8px 20px rgba(0,0,0,.15)">
                @forelse($this->results as $r)
                    <li wire:key="cust-{{ $r['id'] }}">
                        <button type="button" wire:click="select({{ $r['id'] }})" data-test="customer-result" style="display:block;width:100%;text-align:left;padding:.5rem .7rem;border:0;background:transparent;color:inherit;cursor:pointer">
                            <strong>{{ $r['code'] }}</strong> — {{ $r['name'] }}@if($r['dba']) <span style="opacity:.7">(DBA {{ $r['dba'] }})</span>@endif
                            <span style="display:block;font-size:.8em;color:#6b7280">{{ collect([$r['city'], $r['phone']])->filter()->implode(' · ') }}</span>
                        </button>
                    </li>
                @empty
                    <li style="padding:.5rem .7rem;color:#6b7280" data-test="customer-none">No customer matches "{{ $search }}".</li>
                @endforelse
            </ul>
        @else
            <small style="display:block;opacity:.7;margin-top:.2rem">Required. Type an account number, name, DBA, phone or city.</small>
        @endif
    @endif
</div>
