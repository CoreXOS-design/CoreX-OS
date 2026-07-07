{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Property Onboarding</h1>
                <p class="text-sm text-white/60">
                    Send each new agency a secure link where they confirm their imported properties.
                    You do not confirm listings here — the agency does, in their portal.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.importer.index') }}" class="corex-btn-outline corex-btn-on-brand">
                    ← Back to importer
                </a>
            </div>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: var(--ds-green);">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif

    @forelse ($cards as $card)
        @php $agency = $card['agency']; $counts = $card['counts']; $portals = $card['portals']; $events = $card['events']; @endphp
        <div class="rounded-md overflow-hidden"
             style="background: var(--surface); border: 1px solid var(--border);"
             x-data="{ historyOpen: false, createOpen: false }">
            {{-- Agency header --}}
            <div class="flex items-center justify-between gap-4 px-5 py-4" style="border-bottom: 1px solid var(--border);">
                <div class="flex items-center gap-3 min-w-0">
                    @if (!empty($agency->logo_path))
                        <img src="{{ asset('storage/' . $agency->logo_path) }}" class="h-10 w-10 rounded-md object-contain p-1" style="background: var(--surface-2);" alt="">
                    @else
                        <div class="h-10 w-10 rounded-md flex items-center justify-center font-bold"
                             style="background: var(--surface-2); color: var(--text-muted);">
                            {{ strtoupper(mb_substr($agency->name, 0, 1)) }}
                        </div>
                    @endif
                    <div class="min-w-0">
                        <div class="font-semibold truncate" style="color: var(--text-primary);">{{ $agency->name }}</div>
                        <div class="text-xs" style="color: var(--text-muted);">{{ $agency->slug }}</div>
                    </div>
                </div>
                <button type="button" @click="createOpen = true" class="corex-btn-primary">
                    + Create portal
                </button>
            </div>

            {{-- Counts --}}
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2 p-4 text-xs">
                @foreach ([
                    'Pending' => $counts['pending'],
                    'In progress' => $counts['processing'],
                    'Confirmed' => $counts['confirmed'],
                    'Excluded' => $counts['excluded'],
                    'Errors' => $counts['error'],
                    'Total' => $counts['total'],
                ] as $label => $val)
                    <div class="rounded-md p-2 text-center" style="background: var(--surface-2);">
                        <div style="color: var(--text-muted);">{{ $label }}</div>
                        <div class="font-semibold" style="color: var(--text-primary);">{{ number_format((int) $val) }}</div>
                    </div>
                @endforeach
            </div>

            {{-- Portals --}}
            <div class="px-4 pb-4">
                <div class="text-xs font-semibold uppercase tracking-wide mb-2" style="color: var(--text-muted);">Active &amp; recent portals</div>
                @if ($portals->isEmpty())
                    <div class="rounded-md py-8 px-6 text-center" style="background: var(--surface-2); border: 1px solid var(--border);">
                        <div class="w-10 h-10 rounded-full mx-auto mb-3 flex items-center justify-center"
                             style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 015.656 0l1.414 1.414a4 4 0 010 5.656l-1.414 1.414a4 4 0 01-5.656 0M10.172 13.828a4 4 0 01-5.656 0L3.1 12.414a4 4 0 010-5.656L4.515 5.343a4 4 0 015.656 0"/>
                            </svg>
                        </div>
                        <p class="text-sm mb-3" style="color: var(--text-muted);">No portals yet. Generate a secure link for this agency to begin onboarding.</p>
                        <button type="button" @click="createOpen = true" class="corex-btn-primary">+ Create portal</button>
                    </div>
                @else
                    <div class="space-y-2">
                        @foreach ($portals as $portal)
                            <div class="rounded-md p-3 flex flex-wrap items-center gap-3"
                                 style="background: var(--surface-2); border: 1px solid var(--border);">
                                <div class="min-w-[140px]">
                                    <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $portal->label ?? 'Untitled portal' }}</div>
                                    <div class="text-xs" style="color: var(--text-muted);">
                                        Created {{ $portal->created_at->diffForHumans() }}
                                        @if ($portal->expires_at) · exp {{ $portal->expires_at->format('Y-m-d') }} @endif
                                    </div>
                                </div>
                                @php
                                    $badgeClass = match($portal->statusLabel()) {
                                        'Active' => 'ds-badge-success',
                                        'Revoked' => 'ds-badge-danger',
                                        'Expired' => 'ds-badge-warning',
                                        'Completed' => 'ds-badge-info',
                                        default => 'ds-badge-default',
                                    };
                                @endphp
                                <span class="ds-badge {{ $badgeClass }}">{{ $portal->statusLabel() }}</span>
                                <code class="text-xs rounded-md px-2 py-1 flex-1 min-w-[260px] truncate"
                                      style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ $portal->publicUrl() }}</code>
                                <div class="text-xs whitespace-nowrap" style="color: var(--text-muted);">
                                    opens: {{ number_format((int) $portal->open_count) }}
                                    @if ($portal->last_opened_at) · last {{ $portal->last_opened_at->diffForHumans() }} @endif
                                </div>
                                <div class="flex items-center gap-1">
                                    <button type="button"
                                            onclick="navigator.clipboard.writeText('{{ $portal->publicUrl() }}'); this.innerText='Copied'; setTimeout(()=>this.innerText='Copy', 1500);"
                                            class="corex-btn-outline">Copy</button>
                                    <a href="{{ $portal->publicUrl() }}" target="_blank" class="corex-btn-outline">Open</a>
                                    @if ($portal->isActive())
                                        <form method="POST" action="{{ route('admin.importer.portal.extend', $portal) }}" class="inline">
                                            @csrf
                                            <input type="hidden" name="days" value="30">
                                            <button type="submit" class="corex-btn-outline">+30d</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.importer.portal.revoke', $portal) }}" class="inline"
                                              onsubmit="return confirm('Revoke this portal? The agency will no longer be able to use the link.');">
                                            @csrf
                                            <button type="submit" class="corex-btn-outline" style="color: var(--ds-crimson); border-color: color-mix(in srgb, var(--ds-crimson) 40%, transparent);">Revoke</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Activity history --}}
            <div style="border-top: 1px solid var(--border);">
                <button type="button" @click="historyOpen = !historyOpen"
                        class="w-full flex items-center justify-between px-4 py-3 text-left text-sm transition-colors"
                        style="color: var(--text-primary);"
                        onmouseover="this.style.background='var(--surface-2)'"
                        onmouseout="this.style.background=''">
                    <span class="font-semibold">Activity history <span class="text-xs" style="color: var(--text-muted);">({{ number_format($events->count()) }} shown)</span></span>
                    <svg class="w-4 h-4 transition-transform" :class="historyOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: var(--text-muted);">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div x-show="historyOpen" x-cloak class="px-4 pb-4">
                    @if ($events->isEmpty())
                        <div class="text-sm italic" style="color: var(--text-muted);">No activity yet.</div>
                    @else
                        <div class="space-y-1 text-xs">
                            @foreach ($events as $ev)
                                <div class="flex items-start gap-3 py-1.5" style="border-bottom: 1px solid var(--border);">
                                    <div class="whitespace-nowrap w-40" style="color: var(--text-muted);">{{ $ev->created_at->format('Y-m-d H:i') }}</div>
                                    <div class="w-48 truncate" style="color: var(--text-primary);">{{ $ev->actor_label ?? $ev->actor_type }}</div>
                                    <div class="w-48 font-mono truncate" style="color: var(--text-primary);">{{ $ev->event }}</div>
                                    <div class="flex-1 truncate" style="color: var(--text-muted);">
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

            {{-- Create portal modal (teleported to body so it is never clipped by the card's overflow-hidden) --}}
            <template x-teleport="body">
            <div x-show="createOpen" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center p-4"
                 @keydown.escape.window="createOpen = false">
                <div class="fixed inset-0 bg-black/50" @click="createOpen = false"></div>
                <form method="POST" action="{{ route('admin.importer.portal.create') }}"
                      class="relative w-full max-w-md rounded-md p-6 space-y-4"
                      style="background: var(--surface); border: 1px solid var(--border);">
                    @csrf
                    <input type="hidden" name="agency_id" value="{{ $agency->id }}">
                    <h3 class="font-semibold text-lg" style="color: var(--text-primary);">Create onboarding portal</h3>
                    <p class="text-xs" style="color: var(--text-muted);">For <strong>{{ $agency->name }}</strong>. Any currently active portal for this agency will be revoked and replaced.</p>
                    <div>
                        <label for="portal_label_{{ $agency->id }}" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Label (optional)</label>
                        <input id="portal_label_{{ $agency->id }}" type="text" name="label" placeholder="e.g. {{ $agency->name }} go-live"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                    <div>
                        <label for="portal_expires_{{ $agency->id }}" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Expires in (days)</label>
                        <input id="portal_expires_{{ $agency->id }}" type="number" name="expires_in_days" value="30" min="1" max="180"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button type="button" @click="createOpen = false" class="corex-btn-outline">Cancel</button>
                        <button type="submit" class="corex-btn-primary">Create portal</button>
                    </div>
                </form>
            </div>
            </template>
        </div>
    @empty
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No agencies onboarded yet</h3>
            <p class="text-sm mb-4" style="color: var(--text-muted);">No agencies have imported listings yet. Start an import to begin onboarding.</p>
            <a href="{{ route('admin.importer.index') }}" class="corex-btn-primary">Open P24 Importer</a>
        </div>
    @endforelse

</div>
@endsection
