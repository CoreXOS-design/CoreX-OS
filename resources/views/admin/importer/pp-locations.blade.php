@extends('layouts.corex')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Header --}}
    <div class="rounded-md px-6 py-5 space-y-3" style="background:var(--brand-default, #0b2a4a);"
         x-data="ppSyncWidget({
             refreshUrl: '{{ route('admin.importer.pp-locations.refresh') }}',
             statusUrl:  '{{ route('admin.importer.pp-locations.status') }}',
             csrf:       '{{ csrf_token() }}',
         })" x-init="init()">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Private Property Locations</h1>
                <p class="text-sm text-white/60">
                    PP's geography hierarchy cached locally. Used to resolve suburb IDs at submission time — listings are validated against this list before being sent to PP.
                </p>
            </div>
            <button type="button" @click="start()"
                    :disabled="running"
                    class="corex-btn-primary text-sm disabled:opacity-60 disabled:cursor-not-allowed">
                <span x-text="running ? 'Sync in progress…' : 'Refresh from Private Property'"></span>
            </button>
        </div>

        <div x-show="running || finishedAt" x-cloak class="space-y-1.5">
            <div class="flex items-center justify-between text-xs" style="color:rgba(255,255,255,0.8);">
                <span x-text="statusLabel"></span>
                <span x-text="percent + '%'"></span>
            </div>
            <div class="h-2 rounded-md overflow-hidden" style="background:rgba(255,255,255,0.1);">
                <div class="h-full transition-all duration-300"
                     :style="'width: ' + percent + '%; background: ' + (failed ? '#f87171' : (running ? 'var(--brand-button, #0ea5e9)' : '#34d399'))"></div>
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
            <div x-show="!running && !failed && finishedAt" x-cloak class="text-xs mt-1" style="color:#a7f3d0;">
                Sync complete.
                <button type="button" @click="reload()" class="underline ml-2 hover:opacity-80 transition-all duration-300">Reload now</button>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm transition-all duration-300"
             style="background:color-mix(in srgb, var(--ds-green, #059669) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent); color:var(--text-primary, #111827);">
            {{ session('success') }}
        </div>
    @endif

    {{-- Counts + last sync --}}
    <div class="corex-kpi-grid">
        <x-corex-kpi-card title="Provinces" :value="number_format($totals['provinces'])" />
        <x-corex-kpi-card title="Cities" :value="number_format($totals['cities'])" />
        <x-corex-kpi-card title="Suburbs" :value="number_format($totals['suburbs'])" />
        <div class="corex-kpi-card">
            <p class="corex-kpi-title">Last Synced</p>
            <p class="corex-kpi-value" style="font-size:1.125rem;">{{ $lastSyncedAt ? $lastSyncedAt->diffForHumans() : 'never' }}</p>
            @if($lastSyncedAt)
                <p class="text-xs mt-0.5" style="color:var(--text-muted, #9ca3af);">{{ $lastSyncedAt->format('Y-m-d H:i') }}</p>
            @endif
        </div>
    </div>

    @if($lastSyncError)
        <div class="rounded-md px-4 py-3 text-xs transition-all duration-300"
             style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 30%, transparent); color:var(--text-primary, #111827);">
            <span class="font-semibold">Last sync error:</span>
            <span class="break-all">{{ \Illuminate\Support\Str::limit($lastSyncError, 500) }}</span>
        </div>
    @endif

    {{-- Explanatory note in place of the tree (data is intentionally hidden) --}}
    <div class="rounded-md border px-5 py-4 text-sm transition-all duration-300"
         style="background:var(--surface, #ffffff); border-color:var(--border, rgba(0,0,0,0.07)); color:var(--text-secondary, #64748b);">
        The full suburb list is held in the background. Listings are validated against it automatically at submit time — agents are blocked with a clear message if a suburb is not on PP's list.
    </div>
</div>

@push('scripts')
<script>
function ppSyncWidget(cfg) {
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
            return this.running ? 3 : 0;
        },
        get statusLabel() {
            if (this.failed) return 'Sync failed';
            if (this.running) return 'Syncing Private Property locations';
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
            } catch (e) {}
        },

        reload() { window.location.reload(); },
    };
}
</script>
@endpush
@endsection
