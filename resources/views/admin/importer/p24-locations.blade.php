@extends('layouts.corex')

@section('corex-content')
<div class="max-w-5xl mx-auto space-y-5">

    {{-- Header --}}
    <div class="rounded-2xl px-6 py-4 space-y-3" style="background:var(--brand-default, #0b2a4a);"
         x-data="p24SyncWidget({
             refreshUrl: '{{ route('admin.importer.p24-locations.refresh') }}',
             statusUrl:  '{{ route('admin.importer.p24-locations.status') }}',
             csrf:       '{{ csrf_token() }}',
         })" x-init="init()">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-xl font-bold text-white">P24 Locations</h2>
                <div class="text-sm mt-0.5" style="color:rgba(255,255,255,0.6);">
                    The Property24 location tree cached locally — browse Region → Town → Suburb.
                </div>
            </div>
            <button type="button" @click="start()"
                    :disabled="running"
                    class="px-4 py-2 rounded-lg text-sm font-semibold text-white transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
                    style="background:var(--brand-button, #0ea5e9);">
                <span x-text="running ? 'Sync in progress…' : 'Refresh from Property24'"></span>
            </button>
        </div>

        {{-- Progress bar (visible while running or just-completed) --}}
        <div x-show="running || finishedAt" x-cloak class="space-y-1.5">
            <div class="flex items-center justify-between text-xs text-white/80">
                <span x-text="statusLabel"></span>
                <span x-text="percent + '%'"></span>
            </div>
            <div class="h-2 rounded-full overflow-hidden bg-white/10">
                <div class="h-full transition-all duration-500"
                     :class="failed ? 'bg-red-400' : (running ? 'bg-cyan-300' : 'bg-emerald-400')"
                     :style="'width: ' + percent + '%'"></div>
            </div>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-0.5 text-[11px] text-white/70">
                <span>Provinces <span class="font-semibold text-white" x-text="(progress.provinces_done||0) + '/' + (progress.provinces_total||'?')"></span></span>
                <span>Cities <span class="font-semibold text-white" x-text="progress.cities_done || 0"></span></span>
                <span>Suburbs <span class="font-semibold text-white" x-text="(progress.suburbs_done||0).toLocaleString()"></span></span>
                <span class="text-white/50" x-text="progress.current || ''"></span>
            </div>
            <div x-show="failed" x-cloak class="text-xs text-red-200 mt-1">
                <span class="font-semibold">Sync failed:</span>
                <span x-text="progress.error || ''"></span>
            </div>
            <div x-show="!running && !failed && finishedAt" x-cloak class="text-xs text-emerald-200 mt-1">
                Sync complete. Reload the page to see updated counts.
                <button type="button" @click="reload()" class="underline ml-2">Reload now</button>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-xl border px-4 py-3 text-sm" style="background:#ecfdf5;border-color:#a7f3d0;color:#065f46;">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-xl border px-4 py-3 text-sm" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;">
            {{ session('error') }}
        </div>
    @endif

    {{-- Stats + last sync --}}
    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="text-[11px] uppercase tracking-wider text-slate-500 font-semibold">Provinces</div>
            <div class="text-2xl font-bold mt-1" style="color:var(--brand-default,#0b2a4a);">{{ number_format($totals['provinces']) }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="text-[11px] uppercase tracking-wider text-slate-500 font-semibold">Cities / Towns</div>
            <div class="text-2xl font-bold mt-1" style="color:var(--brand-default,#0b2a4a);">{{ number_format($totals['cities']) }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="text-[11px] uppercase tracking-wider text-slate-500 font-semibold">Suburbs</div>
            <div class="text-2xl font-bold mt-1" style="color:var(--brand-default,#0b2a4a);">{{ number_format($totals['suburbs']) }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="text-[11px] uppercase tracking-wider text-slate-500 font-semibold">Last Synced</div>
            <div class="text-sm font-bold mt-1" style="color:var(--brand-default,#0b2a4a);">
                {{ $lastSyncedAt ? $lastSyncedAt->diffForHumans() : 'never' }}
            </div>
            @if($lastSyncedAt)
                <div class="text-[10px] text-slate-400 mt-0.5">{{ $lastSyncedAt->format('Y-m-d H:i') }}</div>
            @endif
        </div>
    </div>

    @if($lastSyncError)
        <div class="rounded-xl border px-4 py-3 text-xs" style="background:#fffbeb;border-color:#fcd34d;color:#92400e;">
            <span class="font-semibold">Last sync error:</span>
            <span class="break-all">{{ \Illuminate\Support\Str::limit($lastSyncError, 500) }}</span>
        </div>
    @endif

    {{-- Tree --}}
    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
        @forelse($provinces as $province)
            <div x-data="p24Province({{ $province->id }})" class="border-b border-slate-200 last:border-b-0">
                <button type="button" @click="toggle()"
                        class="w-full flex items-center justify-between px-5 py-3 hover:bg-slate-50 transition-colors text-left">
                    <div class="flex items-center gap-3">
                        <svg :class="open ? 'rotate-90' : ''" class="w-4 h-4 text-slate-400 transition-transform"
                             xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                        </svg>
                        <span class="text-sm font-semibold" style="color:var(--brand-default,#0b2a4a);">{{ $province->name }}</span>
                        <span class="text-[11px] text-slate-400">{{ $province->cities_count }} {{ $province->cities_count === 1 ? 'city' : 'cities' }}</span>
                    </div>
                </button>
                <div x-show="open" x-cloak class="bg-slate-50/50 px-5 pb-3">
                    <template x-if="loading">
                        <div class="text-xs text-slate-400 py-2">Loading cities…</div>
                    </template>

                    <template x-for="city in cities" :key="city.id">
                        <div class="border-l-2 border-slate-200 ml-2 pl-3 py-1" x-data="p24City(city.id)">
                            <button type="button" @click="toggle()"
                                    class="w-full flex items-center gap-2 py-1 text-left hover:text-blue-600">
                                <svg :class="open ? 'rotate-90' : ''" class="w-3 h-3 text-slate-400 transition-transform"
                                     xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                </svg>
                                <span class="text-xs font-medium text-slate-700" x-text="city.name"></span>
                            </button>
                            <div x-show="open" x-cloak class="ml-5 mt-1">
                                <template x-if="loading">
                                    <div class="text-[11px] text-slate-400">Loading suburbs…</div>
                                </template>
                                <template x-if="!loading && suburbs.length === 0">
                                    <div class="text-[11px] text-slate-400 italic">No suburbs cached for this city.</div>
                                </template>
                                <ul class="space-y-0.5">
                                    <template x-for="s in suburbs" :key="s.id">
                                        <li class="text-[11px] text-slate-600 flex items-center gap-2">
                                            <span class="w-1 h-1 rounded-full bg-slate-300"></span>
                                            <span x-text="s.name"></span>
                                            <span class="text-slate-400 font-mono">#<span x-text="s.p24_id"></span></span>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </div>
                    </template>

                    <template x-if="!loading && cities.length === 0">
                        <div class="text-[11px] text-slate-400 italic py-2">No cities cached for this province.</div>
                    </template>
                </div>
            </div>
        @empty
            <div class="px-5 py-8 text-center text-sm text-slate-500">
                No P24 locations cached yet. Click <span class="font-semibold">Refresh from Property24</span> above to pull the full tree.
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
        _pollHandle: null,

        get percent() {
            const p = this.progress || {};
            const total = +p.provinces_total || 0;
            const done  = +p.provinces_done  || 0;
            if (!this.running && this.finishedAt && !this.failed) return 100;
            if (total > 0) return Math.min(99, Math.round((done / total) * 100));
            // Pre-province-list phase: small bump so the bar visibly moves.
            return this.running ? 3 : 0;
        },
        get statusLabel() {
            if (this.failed) return 'Sync failed';
            if (this.running) return 'Syncing Property24 locations';
            if (this.finishedAt) return 'Sync complete';
            return 'Idle';
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
                this.progress = data || {};
                const s = data?.status || 'idle';
                this.running = (s === 'running');
                this.failed  = (s === 'failed');
                this.finishedAt = (s === 'complete' || s === 'failed') ? (data.finished_at || true) : null;
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
