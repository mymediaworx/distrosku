<x-filament-panels::page>
    <x-filament::section>
        <p><strong>Agent access is {{ $enabled ? 'ON' : 'OFF' }}.</strong> Stage 1 is <strong>read-only</strong>: an AI assistant (for example Claude, as a custom connector) can look up customers, products, prices, order history, AR aging and statements, stock and inbound POs, using one of the tokens below. It can't change anything.</p>
        <p class="mt-2 text-sm">Connector address: <code data-test="mcp-endpoint">{{ $endpoint }}</code> · Header: <code>Authorization: Bearer &lt;token&gt;</code></p>
        @if($newToken)
            <div class="mt-3 p-3 rounded bg-warning-50 dark:bg-warning-950" data-test="new-token"><strong>New token (shown once):</strong> <code class="break-all">{{ $newToken }}</code></div>
        @endif
    </x-filament::section>
    <x-filament::section heading="Access tokens">
        <table class="w-full text-sm"><thead><tr class="border-b"><th class="text-left px-2">Label</th><th class="text-left px-2">Acts as</th><th class="text-left px-2">Last used</th><th class="text-left px-2">Expires</th><th class="text-left px-2">Status</th><th></th></tr></thead><tbody>
        @forelse($tokens as $t)
            <tr class="border-b border-gray-100"><td class="px-2">{{ $t->name }}</td><td class="px-2">{{ $t->user?->name }}</td><td class="px-2">{{ $t->last_used_at?->diffForHumans() ?? 'Never' }}</td><td class="px-2">{{ $t->expires_at?->format('m/d/Y') ?? 'Never' }}</td>
                <td class="px-2">{{ $t->revoked_at ? 'Revoked' : ($t->isUsable() ? 'Active' : 'Expired') }}</td>
                <td class="px-2 text-right">@unless($t->revoked_at)<x-filament::button size="xs" color="danger" wire:click="revoke({{ $t->id }})" wire:confirm="Revoke this token?">Revoke</x-filament::button>@endunless</td></tr>
        @empty<tr><td colspan="6" class="px-2 py-2 text-gray-500">No tokens yet.</td></tr>@endforelse
        </tbody></table>
    </x-filament::section>
    <x-filament::section heading="What the agent looked up (last 50)">
        <table class="w-full text-sm" data-test="agent-log"><thead><tr class="border-b"><th class="text-left px-2">When</th><th class="text-left px-2">User</th><th class="text-left px-2">Tool</th><th class="text-left px-2">Input</th><th class="text-left px-2">Result</th></tr></thead><tbody>
        @forelse($actions as $a)
            <tr class="border-b border-gray-100"><td class="px-2">{{ $a->created_at?->format('m/d g:i A') }}</td><td class="px-2">{{ $a->user?->name }}</td><td class="px-2">{{ $a->tool }}</td><td class="px-2 font-mono text-xs">{{ \Illuminate\Support\Str::limit(json_encode($a->input), 80) }}</td><td class="px-2">{{ $a->ok ? 'OK ('.$a->ms.' ms)' : $a->error }}</td></tr>
        @empty<tr><td colspan="5" class="px-2 py-2 text-gray-500">Nothing yet.</td></tr>@endforelse
        </tbody></table>
    </x-filament::section>
</x-filament-panels::page>
