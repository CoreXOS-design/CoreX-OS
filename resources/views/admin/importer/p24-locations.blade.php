{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Header --}}
    <div class="rounded-md px-6 py-5 space-y-3" style="background:var(--brand-default, #0b2a4a);"
         x-data="p24SyncWidget({
             refreshUrl: '{{ route('admin.importer.p24-locations.refresh') }}',
             statusUrl:  '{{ route('admin.importer.p24-locations.status') }}',
             csrf:       '{{ csrf_token() }}',
         })" x-init="init()">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">P24 Locations</h1>
                <p class="text-sm text-white/60">The Property24 location tree cached locally — browse Region → Town → Suburb.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <button type="button" @click="start()"
                        :disabled="running"
                        class="corex-btn-primary text-sm disabled:opacity-60 disabled:cursor-not-allowed">
                    <span x-text="running ? 'Sync in progress…' : 'Refresh from Property24'"></span>
                </button>
            </div>
        </div>

        {{-- Progress bar (visible while running or just-completed) --}}
        <div x-show="running || finishedAt" x-cloak class="space-y-1.5">
            <div class="flex items-center justify-between text-xs" style="color:rgba(255,255,255,0.8);">
                <span x-text="statusLabel"></span>
                <span x-text="percent + '%'"></span>
            </div>
            <div class="h-2 rounded overflow-hidden" style="background:rgba(255,255,255,0.1);">
                <div class="h-full transition-all duration-300"
                     :style="'width: ' + percent + '%; background: ' + (failed ? 'var(--ds-crimson, #c41e3a)' : (running ? 'var(--brand-button, #0ea5e9)' : 'var(--ds-green, #059669)'))"></div>
            </div>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-0.5 text-[11px]" style="color:rgba(255,255,255,0.7);">
                <span>Provinces <span class="font-semibold text-white" x-text="(progress.provinces_done||0) + '/' + (progress.provinces_total||'?')"></span></span>
                <span>Cities <span class="font-semibold text-white" x-text="progress.cities_done || 0"></span></span>
                <span>Suburbs <span class="font-semibold text-white" x-text="(progress.suburbs_done||0).toLocaleString()"></span></span>
                <span style="color:rgba(255,255,255,0.5);" x-text="progress.current || ''"></span>
            </div>
            <div x-show="failed" x-cloak class="text-xs mt-1" style="color:#fecaca;">
                <span class="font-semibold">Sync failed:</span>
                <span x-text="progress.error || ''"></span>
            </div>
            <div x-show="stuck" x-cloak class="text-xs mt-1" style="color:#fde68a;">
                <span class="font-semibold">Heads up:</span> sync hasn't advanced in 30+ seconds. Check <code style="background:rgba(255,255,255,0.1);padding:1px 4px;border-radius:4px;">storage/logs/p24-sync.log</code> on the server for errors.
            </div>
            <div x-show="!running && !failed && finishedAt" x-cloak class="text-xs mt-1" style="color:#a7f3d0;">
                Sync complete. Reload the page to see updated counts.
                <button type="button" @click="reload()" class="underline ml-2 hover:opacity-80 transition-all duration-300">Reload now</button>
            </div>
            {{-- Stamp-and-sweep summary: how many stale P24 locations this run removed (AT-106). --}}
            <div x-show="!running && !failed && finishedAt && (sweptTotal > 0 || progress.prune_skipped)" x-cloak class="text-[11px] mt-0.5" style="color:rgba(255,255,255,0.6);">
                <span x-show="progress.prune_skipped">Sweep skipped — P24 returned too few locations this run; the tree was left intact.</span>
                <span x-show="!progress.prune_skipped && sweptTotal > 0"
                      x-text="'Swept ' + sweptTotal.toLocaleString() + ' stale location' + (sweptTotal === 1 ? '' : 's') + ' (' + (progress.pruned_cities || 0) + ' cities, ' + (progress.pruned_suburbs || 0).toLocaleString() + ' suburbs)' + ((progress.props_remediated || 0) > 0 ? ' · ' + progress.props_remediated + ' propert' + (progress.props_remediated === 1 ? 'y' : 'ies') + ' remediated' : '')"></span>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md border px-4 py-3 text-sm transition-all duration-300"
             style="background:color-mix(in srgb, var(--ds-green, #059669) 10%, transparent); border-color:color-mix(in srgb, var(--ds-green, #059669) 30%, transparent); color:var(--text-primary, #111827);">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-md border px-4 py-3 text-sm transition-all duration-300"
             style="background:color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent); border-color:color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent); color:var(--text-primary, #111827);">
            {{ session('error') }}
        </div>
    @endif

    {{-- Stats + last sync --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        @foreach([
            ['Provinces',     number_format($totals['provinces'])],
            ['Cities / Towns', number_format($totals['cities'])],
            ['Suburbs',       number_format($totals['suburbs'])],
        ] as [$label, $value])
            <div class="rounded-md border p-4 transition-all duration-300"
                 style="background:var(--surface, #ffffff); border-color:var(--border, rgba(0,0,0,0.07));">
                <div class="text-[11px] uppercase tracking-wider font-semibold" style="color:var(--text-secondary, #64748b);">{{ $label }}</div>
                <div class="text-2xl font-bold mt-1" style="color:var(--text-primary, #111827);">{{ $value }}</div>
            </div>
        @endforeach
        <div class="rounded-md border p-4 transition-all duration-300"
             style="background:var(--surface, #ffffff); border-color:var(--border, rgba(0,0,0,0.07));">
            <div class="text-[11px] uppercase tracking-wider font-semibold" style="color:var(--text-secondary, #64748b);">Last Synced</div>
            <div class="text-sm font-bold mt-1" style="color:var(--text-primary, #111827);">
                {{ $lastSyncedAt ? $lastSyncedAt->diffForHumans() : 'never' }}
            </div>
            @if($lastSyncedAt)
                <div class="text-[10px] mt-0.5" style="color:var(--text-muted, #9ca3af);">{{ $lastSyncedAt->format('Y-m-d H:i') }}</div>
            @endif
        </div>
    </div>

    @if($lastSyncError)
        <div class="rounded-md border px-4 py-3 text-xs transition-all duration-300"
             style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 10%, transparent); border-color:color-mix(in srgb, var(--ds-amber, #f59e0b) 30%, transparent); color:var(--text-primary, #111827);">
            <span class="font-semibold">Last sync error:</span>
            <span class="break-all">{{ \Illuminate\Support\Str::limit($lastSyncError, 500) }}</span>
        </div>
    @endif

    {{-- Tree --}}
    <div class="rounded-md border overflow-hidden transition-all duration-300"
         style="background:var(--surface, #ffffff); border-color:var(--border, rgba(0,0,0,0.07));">
        @forelse($provinces as $province)
            <div x-data="p24Province({{ $province->id }})"
                 class="border-b last:border-b-0"
                 style="border-color:var(--border, rgba(0,0,0,0.07));">
                <button type="button" @click="toggle()"
                        class="w-full flex items-center justify-between px-5 py-3 text-left transition-all duration-300"
                        style="background:transparent;"
                        onmouseover="this.style.background='var(--surface-2, #f0f2f8)'"
                        onmouseout="this.style.background='transparent'">
                    <div class="flex items-center gap-3">
                        <svg :class="open ? 'rotate-90' : ''" class="w-4 h-4 transition-all duration-300"
                             style="color:var(--brand-icon, #0ea5e9);"
                             xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                        </svg>
                        <span class="text-sm font-semibold" style="color:var(--brand-default, #0b2a4a);">{{ $province->name }}</span>
                        <span class="text-[11px]" style="color:var(--text-muted, #9ca3af);">{{ $province->cities_count }} {{ $province->cities_count === 1 ? 'city' : 'cities' }}</span>
                    </div>
                </button>
                <div x-show="open" x-cloak class="px-5 pb-3" style="background:var(--surface-2, #f0f2f8);">
                    <template x-if="loading">
                        <div class="text-xs py-2" style="color:var(--text-muted, #9ca3af);">Loading cities…</div>
                    </template>

                    <template x-for="city in cities" :key="city.id">
                        <div class="border-l-2 ml-2 pl-3 py-1" style="border-color:var(--border, rgba(0,0,0,0.07));" x-data="p24City(city.id)">
                            <button type="button" @click="toggle()"
                                    class="w-full flex items-center gap-2 py-1 text-left transition-all duration-300"
                                    onmouseover="this.style.color='var(--brand-icon, #0ea5e9)'"
                                    onmouseout="this.style.color='var(--text-primary, #111827)'">
                                <svg :class="open ? 'rotate-90' : ''" class="w-3 h-3 transition-all duration-300"
                                     style="color:var(--brand-icon, #0ea5e9);"
                                     xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                </svg>
                                <span class="text-xs font-medium" style="color:var(--text-primary, #111827);" x-text="city.name"></span>
                            </button>
                            <div x-show="open" x-cloak class="ml-5 mt-1">
                                <template x-if="loading">
                                    <div class="text-[11px]" style="color:var(--text-muted, #9ca3af);">Loading suburbs…</div>
                                </template>
                                <template x-if="!loading && suburbs.length === 0">
                                    <div class="text-[11px] italic" style="color:var(--text-muted, #9ca3af);">No suburbs cached for this city.</div>
                                </template>
                                <ul class="space-y-0.5">
                                    <template x-for="s in suburbs" :key="s.id">
                                        <li class="text-[11px] flex items-center gap-2" style="color:var(--text-secondary, #64748b);">
                                            <span class="w-1 h-1 rounded-full" style="background:var(--brand-icon, #0ea5e9);"></span>
                                            <span x-text="s.name"></span>
                                            <span class="font-mono" style="color:var(--text-muted, #9ca3af);">#<span x-text="s.p24_id"></span></span>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </div>
                    </template>

                    <template x-if="!loading && cities.length === 0">
                        <div class="text-[11px] italic py-2" style="color:var(--text-muted, #9ca3af);">No cities cached for this province.</div>
                    </template>
                </div>
            </div>
        @empty
            <div class="px-5 py-8 text-center text-sm" style="color:var(--text-secondary, #64748b);">
                No P24 locations cached yet. Click <span class="font-semibold" style="color:var(--text-primary, #111827);">Refresh from Property24</span> above to pull the full tree.
            </div>
        @endforelse
    </div>
</div>

@push('scripts')
<script>
function p24SyncWidget(cfg) {
    return {
        progress: { status: 'idle' },
        running: false,
        finishedAt: null,
        failed: false,
        stuck: false,
        _stuckSince: null,
        _pollHandle: null,

        get percent() {
            const p = this.progress || {};
            const total = +p.provinces_total || 0;
            const done  = +p.provinces_done  || 0;
            if (!this.running && this.finishedAt && !this.failed) return 100;
            if (total > 0) return Math.min(99, Math.round((done / total) * 100));
            return this.running ? 3 : 0;
        },
        get statusLabel() {
            if (this.failed) return 'Sync failed';
            if (this.running) return 'Syncing Property24 locations';
            if (this.finishedAt) return 'Sync complete';
            return 'Idle';
        },
        get sweptTotal() {
            const p = this.progress || {};
            return (+p.pruned_provinces || 0) + (+p.pruned_cities || 0) + (+p.pruned_suburbs || 0);
        },

        async init() {
            await this.poll();
            if (this.running) this._startPolling();
        },

        async start() {
            this.failed = false;
            this.finishedAt = null;
            const r = await fetch(cfg.refreshUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' },
            });
            const body = await r.json().catch(() => ({}));
            if (!r.ok) {
                this.failed = true;
                this.progress = { ...this.progress, status: 'failed', error: body.message || 'HTTP ' + r.status };
                return;
            }
            this.running = true;
            this._startPolling();
        },

        _startPolling() {
            if (this._pollHandle) return;
            this._pollHandle = setInterval(() => this.poll(), 2500);
        },

        async poll() {
            try {
                const r = await fetch(cfg.statusUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                const data = await r.json();
                const prev = this.progress || {};
                this.progress = data || {};
                const s = data?.status || 'idle';
                this.running = (s === 'running');
                this.failed  = (s === 'failed');
                this.finishedAt = (s === 'complete' || s === 'failed') ? (data.finished_at || true) : null;

                if (this.running) {
                    const moved = (prev.provinces_done   !== data.provinces_done)
                               || (prev.cities_done      !== data.cities_done)
                               || (prev.suburbs_done     !== data.suburbs_done)
                               || (prev.current          !== data.current);
                    if (moved || !this._stuckSince) {
                        this._stuckSince = Date.now();
                        this.stuck = false;
                    } else if (Date.now() - this._stuckSince > 30000) {
                        this.stuck = true;
                    }
                } else {
                    this.stuck = false;
                    this._stuckSince = null;
                }

                if (!this.running && this._pollHandle) {
                    clearInterval(this._pollHandle);
                    this._pollHandle = null;
                }
            } catch (e) {
                // Swallow — next tick will retry.
            }
        },

        reload() { window.location.reload(); },
    };
}

function p24Province(id) {
    return {
        open: false, loading: false, loaded: false, cities: [],
        async toggle() {
            this.open = !this.open;
            if (this.open && !this.loaded) await this.load();
        },
        async load() {
            this.loading = true;
            try {
                const r = await fetch('/api/v1/p24/cities?all=1&province_id=' + id, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                const j = await r.json();
                this.cities = j.data || [];
                this.loaded = true;
            } finally { this.loading = false; }
        },
    };
}
function p24City(id) {
    return {
        open: false, loading: false, loaded: false, suburbs: [],
        async toggle() {
            this.open = !this.open;
            if (this.open && !this.loaded) await this.load();
        },
        async load() {
            this.loading = true;
            try {
                const r = await fetch('/api/v1/p24/suburbs?all=1&city_id=' + id, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                const j = await r.json();
                this.suburbs = j.data || [];
                this.loaded = true;
            } finally { this.loading = false; }
        },
    };
}
</script>
@endpush
@endsection
