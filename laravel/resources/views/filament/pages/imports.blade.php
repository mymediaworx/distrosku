<x-filament-panels::page>
<style>
 .imp-card{background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:.75rem;padding:1rem}.dark .imp-card{background:rgb(255 255 255/.04)}
 .imp-grid{display:grid;gap:.75rem;grid-template-columns:1fr}@media(min-width:768px){.imp-grid{grid-template-columns:repeat(4,1fr)}}
 .imp-tbl{width:100%;border-collapse:collapse;font-size:.8rem}.imp-tbl th,.imp-tbl td{padding:.35rem .5rem;border-bottom:1px solid rgba(0,0,0,.07);text-align:left;vertical-align:top}
 .imp-in{width:100%;border:1px solid #d1d5db;border-radius:.5rem;padding:.45rem .6rem;font-size:.875rem;background:transparent}
 .imp-pill{display:inline-block;border-radius:999px;padding:.05rem .5rem;font-size:.72rem;background:#e5e7eb}.imp-pill.error,.imp-pill.failed{background:#fee2e2;color:#991b1b}.imp-pill.valid,.imp-pill.committed{background:#d1fae5;color:#065f46}.imp-pill.unmatched{background:#fef3c7;color:#92400e}
 .up{color:#b91c1c}.down{color:#047857}
</style>
@if(! $b)
    <div class="imp-card" data-test="new-import">
        <h3 style="font-weight:600;margin-bottom:.5rem">New import</h3>
        <div class="imp-grid">
            <label>Type<select class="imp-in" wire:model.live="type">
                <optgroup label="Spreadsheet import">@foreach($types as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</optgroup>
                <optgroup label="Vendor">@foreach($vendorTypes as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</optgroup></select></label>
            @if($type === 'vendor_costs')
                <label>Vendor<select class="imp-in" wire:model="vendorId"><option value="">Choose…</option>@foreach($vendors as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></label>
                <label>Effective date (blank = today)<input type="date" class="imp-in" wire:model="effectiveOn"></label>
            @endif
            <label>File (.csv or .xlsx)<input type="file" class="imp-in" wire:model="file" accept=".csv,.xlsx,.txt"></label>
        </div>
        <div style="margin-top:.75rem;display:flex;gap:.75rem;align-items:center"><x-filament::button wire:click="upload" wire:loading.attr="disabled">Upload and read</x-filament::button>
            <span wire:loading wire:target="file,upload" style="font-size:.8rem;color:#6b7280">Working…</span>
            <span style="font-size:.8rem">Blank template: <a style="text-decoration:underline" href="{{ route('imports.template', [$type, 'csv']) }}">CSV</a> · <a style="text-decoration:underline" href="{{ route('imports.template', [$type, 'xlsx']) }}">XLSX</a></span></div>
        @error('file')<p style="color:#dc2626;font-size:.8rem">{{ $message }}</p>@enderror
    </div>
    <div class="imp-card"><div style="display:flex;justify-content:space-between;align-items:center"><h3 style="font-weight:600">Recent imports</h3>
        @if($pendingCosts)<x-filament::button size="sm" color="gray" wire:click="applyDueCosts">Apply due cost changes ({{ $pendingCosts }} scheduled)</x-filament::button>@endif</div>
        <table class="imp-tbl"><tr><th>#</th><th>Type</th><th>File</th><th>Rows</th><th>Status</th><th>By</th><th>When</th></tr>
        @forelse($recent as $r)<tr><td><a style="text-decoration:underline" href="?batch={{ $r->id }}">{{ $r->id }}</a></td><td>{{ \App\Imports\ImportRegistry::make($r->type)->label() }}@if($r->vendor) · {{ $r->vendor->name }}@endif</td>
            <td>{{ $r->original_name }}</td><td>{{ $r->total_rows }}</td><td><span class="imp-pill">{{ $r->status }}</span></td><td>{{ $r->user?->name }}</td><td>{{ $r->created_at->format('M j g:i A') }}</td></tr>
        @empty<tr><td colspan="7" style="color:#6b7280">No imports yet.</td></tr>@endforelse</table></div>
@else
    <div class="imp-card" data-test="batch" @if(in_array($b->status, ['validating', 'committing'], true)) wire:poll.1500ms="tick" @endif>
        <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:.5rem"><div><strong>Import #{{ $b->id }} · {{ $handler->label() }}</strong>@if($b->vendor) · {{ $b->vendor->name }}@endif
            @if($b->effective_on) · effective {{ $b->effective_on->format('M j, Y') }}@endif<br><span style="font-size:.8rem;color:#6b7280">{{ $b->original_name }} · {{ $b->total_rows }} rows</span></div>
            <div><span class="imp-pill" data-test="status">{{ $b->status }}</span> <x-filament::button size="sm" color="gray" wire:click="closeBatch">Back to imports</x-filament::button></div></div>
        @if(in_array($b->status, ['validating', 'committing'], true))
            @php($done = $b->status === 'validating' ? $b->rows()->where('status', '!=', 'pending')->count() : $b->rows()->whereIn('status', ['committed', 'failed'])->count())
            <p style="margin-top:.5rem">{{ $b->status === 'validating' ? 'Checking' : 'Importing' }}… {{ $done }} / {{ $b->status === 'validating' ? $b->total_rows : $b->valid_rows }} rows (you can leave this page; it continues in the background).</p>
        @endif
        @if($b->status === 'validated' || $b->status === 'done')
            <p style="margin-top:.5rem" data-test="summary">Dry run: <strong>{{ $b->valid_rows }}</strong> ready ·
                <strong>{{ $b->rows()->where('action', 'create')->whereIn('status', ['valid', 'committed'])->count() }}</strong> new ·
                <strong>{{ $b->rows()->whereIn('action', ['update', 'schedule'])->whereIn('status', ['valid', 'committed'])->count() }}</strong> changed ·
                <strong>{{ $b->rows()->where('action', 'unchanged')->whereIn('status', ['valid', 'committed'])->count() }}</strong> unchanged ·
                <strong style="color:#b91c1c">{{ $b->rows()->where('status', 'error')->count() }}</strong> errors
                @if($b->type === 'vendor_costs') · <strong style="color:#b45309">{{ $b->rows()->where('status', 'unmatched')->count() }}</strong> unmatched @endif</p>
            @if($b->status === 'done')<p class="down" data-test="done">Imported: {{ $b->created_count }} created, {{ $b->updated_count }} updated{{ $b->type === 'vendor_costs' && $b->effective_on?->isFuture() ? ' (scheduled)' : '' }}, {{ $b->unchanged_count }} unchanged.</p>@endif
        @endif
        @if($b->message)<p class="up">{{ $b->message }}</p>@endif
        <p style="margin-top:.5rem;font-size:.85rem">@if($b->error_rows || $b->rows()->where('status', 'failed')->exists())<a style="text-decoration:underline" href="{{ route('imports.errors', $b) }}">Download error report (CSV)</a> · @endif
            Template: <a style="text-decoration:underline" href="{{ route('imports.template', [$b->type, 'csv']) }}">CSV</a> · <a style="text-decoration:underline" href="{{ route('imports.template', [$b->type, 'xlsx']) }}">XLSX</a></p>
    </div>
    @if(in_array($b->status, ['uploaded', 'validated'], true))
        <div class="imp-card"><h3 style="font-weight:600;margin-bottom:.5rem">Column mapping</h3>
            <div class="imp-grid">
                @foreach($handler->fields() as $k => $f)
                    <label style="font-size:.8rem">{{ $f['label'] }}@if($f['required'] ?? false) <span style="color:#dc2626">*</span>@endif
                        <select class="imp-in" wire:model="mapping.{{ $k }}" data-test="map-{{ $k }}"><option value="">— skip —</option>@foreach($b->headers as $h)<option value="{{ $h }}">{{ $h }}</option>@endforeach</select>
                        @if($f['help'] ?? null)<span style="color:#6b7280;font-size:.7rem">{{ $f['help'] }}</span>@endif</label>
                @endforeach
            </div>
            <div style="margin-top:.75rem;display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
                @if($savedMappings->isNotEmpty())<select class="imp-in" style="width:auto" wire:model="loadMappingId"><option value="">Saved templates…</option>@foreach($savedMappings as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
                    <x-filament::button size="sm" color="gray" wire:click="loadMapping">Load</x-filament::button>@endif
                <input class="imp-in" style="width:14rem" wire:model="mappingName" placeholder="{{ $b->vendor ? $b->vendor->name.' price file' : 'Template name' }}">
                <x-filament::button size="sm" color="gray" wire:click="saveMapping">Save mapping template</x-filament::button>
                <x-filament::button wire:click="dryRun" data-test="dry-run">Dry run (check every row)</x-filament::button>
                @if($b->status === 'validated' && $b->valid_rows)<x-filament::button color="success" wire:click="commit" wire:confirm="Import {{ $b->valid_rows }} valid rows? Rows with errors are skipped." data-test="commit">Commit {{ $b->valid_rows }} rows</x-filament::button>@endif
            </div>
        </div>
    @endif
    @if($b->status !== 'uploaded')
        <div class="imp-card"><div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.5rem"><strong>Preview</strong> <span style="font-size:.75rem;color:#6b7280">(first 100)</span>
            <select class="imp-in" style="width:auto" wire:model.live="show"><option value="all">All rows</option><option value="errors">Errors</option><option value="changes">New / changed</option>@if($b->type === 'vendor_costs')<option value="unmatched">Unmatched</option>@endif</select></div>
            <table class="imp-tbl" data-test="preview">
                <tr><th>Row</th><th>Status</th><th>Key</th><th>Action</th><th>{{ $b->type === 'vendor_costs' ? 'Old cost → new cost (% change)' : 'Changes (old → new)' }}</th><th>Problems</th></tr>
                @foreach($rows as $r)
                    <tr><td>{{ $r->row_no }}</td><td><span class="imp-pill {{ $r->status }}">{{ $r->status }}</span></td><td>{{ $r->key_label }}</td><td>{{ $r->action }}</td>
                        <td>@foreach((array) $r->diff as $f => $d)
                            @if($b->type === 'vendor_costs' && $f === 'cost')<span>${{ number_format((float) $d[0], 2) }} → ${{ number_format((float) $d[1], 2) }}</span>
                                @if(isset($d[2]))<span class="{{ $d[2] > 0 ? 'up' : 'down' }}">({{ $d[2] > 0 ? '+' : '' }}{{ $d[2] }}%)</span>@endif
                            @else<div><strong>{{ $f }}</strong>: {{ is_null($d[0]) ? '∅' : $d[0] }} → {{ $d[1] }}</div>@endif
                        @endforeach</td>
                        <td class="up">@if($r->status === 'unmatched')No product matches this vendor item # / UPC @else{{ implode(' ', (array) $r->errors) }}@endif</td></tr>
                @endforeach
            </table></div>
    @endif
@endif
</x-filament-panels::page>
