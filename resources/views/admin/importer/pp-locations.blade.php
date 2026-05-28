@extends('layouts.corex')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
<div class="max-w-5xl mx-auto space-y-4">

    {{-- Header --}}
    <div class="rounded-md px-6 py-4 space-y-3" style="background:var(--brand-default, #0b2a4a);"
         x-data="ppSyncWidget({
             refreshUrl: '{{ route('admin.importer.pp-locations.refresh') }}',
             statusUrl:  '{{ route('admin.importer.pp-locations.status') }}',
             csrf:       '{{ csrf_token() }}',
         })" x-init="init()">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-xl font-bold text-white tracking-tight">Private Property Locations</h2>
                <div class="text-sm mt-0.5" style="color:rgba(255,255,255,0.6);">
                    PP's geography hierarchy cached locally. Used to resolve suburb IDs at submission time — listings are validated against this list before being sent to PP.
                </div>
            </div>
            <button type="button" @click="start()"
                    :disabled="running"
                    class="px-4 py-2 rounded-md text-sm font-semibold text-white shadow-lg transition-all duration-300 disabled:opacity-60 disabled:cursor-not-allowed hover:opacity-90"
                    style="background:var(--brand-button, #0ea5e9);">
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
        <div class="rounded-md border px-4 py-3 text-sm transition-all duration-300"
             style="background:color-mix(in srgb, #34d399 14%, var(--surface, #ffffff)); border-color:color-mix(in srgb, #34d399 35%, var(--border, rgba(0,0,0,0.07))); color:#065f46;">
            {{ session('success') }}
        </div>
    @endif

    {{-- Counts + last sync --}}
    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
        @foreach([
            ['Provinces', number_format($totals['provinces'])],
            ['Cities',    number_format($totals['cities'])],
            ['Suburbs',   number_format($totals['suburbs'])],
        ] as [$label, $value])
            <div class="rounded-md border p-4 transition-all duration-300"
                 style="background:var(--surface, #ffffff); border-color:var(--border, rgba(0,0,0,0.07));">
                <div class="text-[11px] uppercase tracking-wider font-semibold" style="color:var(--text-secondary, #64748b);">{{ $label }}</div>
                <div class="text-2xl font-bold mt-1" style="color:var(--brand-default, #0b2a4a);">{{ $value }}</div>
            </div>
        @endforeach
        <div class="rounded-md border p-4 transition-all duration-300"
             style="background:var(--surface, #ffffff); border-color:var(--border, rgba(0,0,0,0.07));">
            <div class="text-[11px] uppercase tracking-wider font-semibold" style="color:var(--text-secondary, #64748b);">Last Synced</div>
            <div class="text-sm font-bold mt-1" style="color:var(--brand-default, #0b2a4a);">
                {{ $lastSyncedAt ? $lastSyncedAt->diffForHumans() : 'never' }}
            </div>
            @if($lastSyncedAt)
                <div class="text-[10px] mt-0.5" style="color:var(--text-muted, #9ca3af);">{{ $lastSyncedAt->format('Y-m-d H:i') }}</div>
            @endif
        </div>
    </div>

    @if($lastSyncError)
        <div class="rounded-md border px-4 py-3 text-xs transition-all duration-300"
             style="background:color-mix(in srgb, #f59e0b 12%, var(--surface, #ffffff)); border-color:color-mix(in srgb, #f59e0b 40%, var(--border, rgba(0,0,0,0.07))); color:#92400e;">
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
