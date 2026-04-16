@extends('layouts.corex')

@section('corex-content')
<div class="max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div class="rounded-md px-6 py-4 flex items-center justify-between" style="background:var(--brand-default, #0b2a4a);">
        <div>
            <h2 class="text-xl font-bold text-white">Property Onboarding</h2>
            <div class="text-sm mt-0.5" style="color:rgba(255,255,255,0.7);">
                Send each new agency a secure link where they confirm their imported properties.
                You do not confirm listings here — the agency does, in their portal.
            </div>
        </div>
        <a href="{{ route('admin.importer.index') }}"
           class="rounded-md px-3 py-1.5 text-xs bg-white/10 hover:bg-white/20 text-white">
            ← Back to importer
        </a>
    </div>

    @if (session('status'))
        <div class="rounded-md bg-emerald-500/10 border border-emerald-500/30 text-emerald-700 text-sm px-4 py-2">
            {{ session('status') }}
        </div>
    @endif

    @forelse ($cards as $card)
        @php $agency = $card['agency']; $counts = $card['counts']; $portals = $card['portals']; $events = $card['events']; @endphp
        <div class="rounded-md bg-surface border border-subtle/30 overflow-hidden"
             x-data="{ historyOpen: false, createOpen: false }">
            {{-- Agency header --}}
            <div class="flex items-center justify-between gap-4 px-5 py-4 border-b border-subtle/30">
                <div class="flex items-center gap-3 min-w-0">
                    @if (!empty($agency->logo_path))
                        <img src="{{ asset('storage/' . $agency->logo_path) }}" class="h-10 w-10 rounded-md object-contain bg-surface-2 p-1">
                    @else
                        <div class="h-10 w-10 rounded-md bg-surface-2 flex items-center justify-center font-bold text-muted">{{ strtoupper(mb_substr($agency->name, 0, 1)) }}</div>
                    @endif
                    <div class="min-w-0">
                        <div class="font-semibold truncate">{{ $agency->name }}</div>
                        <div class="text-xs text-muted">{{ $agency->slug }}</div>
                    </div>
                </div>
                <button type="button" @click="createOpen = true"
                        class="rounded-md px-3 py-1.5 text-xs text-white"
                        style="background:var(--brand-button, #0ea5e9);">
                    + Create portal
                </button>
            </div>

            {{-- Counts --}}
            <div class="grid grid-cols-2 sm:grid-cols-6 gap-2 p-4 text-xs">
                <div class="rounded-md bg-surface-2 p-2 text-center"><div class="text-muted">Pending</div><div class="font-semibold">{{ $counts['pending'] }}</div></div>
                <div class="rounded-md bg-surface-2 p-2 text-center"><div class="text-muted">In progress</div><div class="font-semibold">{{ $counts['processing'] }}</div></div>
                <div class="rounded-md bg-surface-2 p-2 text-center"><div class="text-muted">Confirmed</div><div class="font-semibold">{{ $counts['confirmed'] }}</div></div>
                <div class="rounded-md bg-surface-2 p-2 text-center"><div class="text-muted">Excluded</div><div class="font-semibold">{{ $counts['excluded'] }}</div></div>
                <div class="rounded-md bg-surface-2 p-2 text-center"><div class="text-muted">Errors</div><div class="font-semibold">{{ $counts['error'] }}</div></div>
                <div class="rounded-md bg-surface-2 p-2 text-center"><div class="text-muted">Total</div><div class="font-semibold">{{ $counts['total'] }}</div></div>
            </div>

            {{-- Portals --}}
            <div class="px-4 pb-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-muted mb-2">Active &amp; recent portals</div>
                @if ($portals->isEmpty())
                    <div class="text-sm text-muted italic">No portals yet. Click <em>Create portal</em> to generate a link for this agency.</div>
                @else
                    <div class="space-y-2">
                        @foreach ($portals as $portal)
                            <div class="rounded-md bg-surface-2 border border-subtle/20 p-3 flex flex-wrap items-center gap-3">
                                <div class="min-w-[140px]">
                                    <div class="text-sm font-medium">{{ $portal->label ?? 'Untitled portal' }}</div>
                                    <div class="text-xs text-muted">
                                        Created {{ $portal->created_at->diffForHumans() }} ·
                                        @if ($portal->expires_at) exp {{ $portal->expires_at->format('Y-m-d') }} @endif
                                    </div>
                                </div>
                                @php
                                    $badge = match($portal->statusLabel()) {
                                        'Active' => 'bg-emerald-500/20 text-emerald-700',
                                        'Revoked' => 'bg-red-500/20 text-red-700',
                                        'Expired' => 'bg-amber-500/20 text-amber-700',
                                        'Completed' => 'bg-sky-500/20 text-sky-700',
                                        default => 'bg-surface-2',
                                    };
                                @endphp
                                <span class="rounded-md px-2 py-0.5 text-xs {{ $badge }}">{{ $portal->statusLabel() }}</span>
                                <code class="text-xs bg-surface border border-subtle/30 rounded px-2 py-1 flex-1 min-w-[260px] truncate">{{ $portal->publicUrl() }}</code>
                                <div class="text-xs text-muted whitespace-nowrap">
                                    opens: {{ $portal->open_count }}
                                    @if ($portal->last_opened_at) · last {{ $portal->last_opened_at->diffForHumans() }} @endif
                                </div>
                                <div class="flex items-center gap-1">
                                    <button type="button"
                                            onclick="navigator.clipboard.writeText('{{ $portal->publicUrl() }}'); this.innerText='Copied'; setTimeout(()=>this.innerText='Copy', 1500);"
                                            class="rounded-md px-2 py-1 text-xs bg-surface border border-subtle">Copy</button>
                                    <a href="{{ $portal->publicUrl() }}" target="_blank"
                                       class="rounded-md px-2 py-1 text-xs bg-surface border border-subtle">Open</a>
                                    @if ($portal->isActive())
                                        <form method="POST" action="{{ route('admin.importer.portal.extend', $portal) }}" class="inline">
                                            @csrf
                                            <input type="hidden" name="days" value="30">
                                            <button type="submit" class="rounded-md px-2 py-1 text-xs bg-surface border border-subtle">+30d</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.importer.portal.revoke', $portal) }}" class="inline"
                                              onsubmit="return confirm('Revoke this portal? The agency will no longer be able to use the link.');">
                                            @csrf
                                            <button type="submit" class="rounded-md px-2 py-1 text-xs text-red-500 bg-surface border border-subtle">Revoke</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Activity history --}}
            <div class="border-t border-subtle/20">
                <button type="button" @click="historyOpen = !historyOpen"
                        class="w-full flex items-center justify-between px-4 py-3 text-left text-sm hover:bg-surface-2">
                    <span class="font-semibold">Activity history <span class="text-muted text-xs">({{ $events->count() }} shown)</span></span>
                    <span x-text="historyOpen ? '▾' : '▸'" class="text-muted"></span>
                </button>
                <div x-show="historyOpen" x-cloak class="px-4 pb-4">
                    @if ($events->isEmpty())
                        <div class="text-sm text-muted italic">No activity yet.</div>
                    @else
                        <div class="space-y-1 text-xs">
                            @foreach ($events as $ev)
                                <div class="flex items-start gap-3 py-1.5 border-b border-subtle/10">
                                    <div class="text-muted whitespace-nowrap w-40">{{ $ev->created_at->format('Y-m-d H:i') }}</div>
                                    <div class="w-48 truncate">{{ $ev->actor_label ?? $ev->actor_type }}</div>
                                    <div class="w-48 font-mono truncate">{{ $ev->event }}</div>
                                    <div class="flex-1 truncate text-muted">
                                        @if ($ev->target_external_id) listing #{{ $ev->target_external_id }} @endif
                                        @if (!empty($ev->meta_json))
                                            · {{ collect($ev->meta_json)->map(fn($v,$k) => "$k=".(is_array($v)?json_encode($v):$v))->implode(', ') }}
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- Create portal modal --}}
            <div x-show="createOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
                 @keydown.escape.window="createOpen = false">
                <div class="fixed inset-0 bg-black/50" @click="createOpen = false"></div>
                <form method="POST" action="{{ route('admin.importer.portal.create') }}"
                      class="relative w-full max-w-md bg-surface rounded-md border border-subtle p-6 space-y-4">
                    @csrf
                    <input type="hidden" name="agency_id" value="{{ $agency->id }}">
                    <h3 class="font-semibold text-lg">Create onboarding portal</h3>
                    <p class="text-xs text-muted">For <strong>{{ $agency->name }}</strong>. Any currently active portal for this agency will be revoked and replaced.</p>
                    <div>
                        <label class="text-xs text-muted">Label (optional)</label>
                        <input type="text" name="label" placeholder="e.g. {{ $agency->name }} go-live"
                               class="w-full rounded-md bg-surface-2 border border-subtle px-2 py-1.5 text-sm">
                    </div>
                    <div>
                        <label class="text-xs text-muted">Expires in (days)</label>
                        <input type="number" name="expires_in_days" value="30" min="1" max="180"
                               class="w-full rounded-md bg-surface-2 border border-subtle px-2 py-1.5 text-sm">
                    </div>
                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button type="button" @click="createOpen = false"
                                class="rounded-md px-3 py-1.5 text-xs bg-surface-2 border border-subtle">Cancel</button>
                        <button type="submit"
                                class="rounded-md px-3 py-1.5 text-xs text-white"
                                style="background:var(--brand-button, #0ea5e9);">Create portal</button>
                    </div>
                </form>
            </div>
        </div>
    @empty
        <div class="rounded-md bg-surface p-10 border border-subtle/30 text-center text-muted">
            No agencies have imported listings yet. Start an import from <a href="{{ route('admin.importer.index') }}" class="underline">P24 Importer</a>.
        </div>
    @endforelse

</div>
@endsection
