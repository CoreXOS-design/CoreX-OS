@extends('layouts.corex-app')

{{-- System Developer → Server Health. Lean v1: read-only, now-focused live
     vitals, ~10s poll of /api/v1/system-health. No historical storage; the CPU
     sparkline is drawn from in-page samples only (nothing persisted). --}}

@section('corex-content')
<div class="w-full space-y-5"
     x-data="serverHealth({ dataUrl: '{{ route('api.v1.system-health') }}', amber: {{ $diskAmber }}, red: {{ $diskRed }} })"
     x-init="start()">

    {{-- Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold" style="color:#fff;">Server Health</h1>
                <p class="text-sm mt-1" style="color:rgba(255,255,255,0.7);">Live server vitals · read-only · auto-refresh every 10s</p>
            </div>
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center gap-2 text-xs px-3 py-1.5 rounded"
                      style="background:rgba(255,255,255,0.1); color:#fff;">
                    <span class="inline-block w-2 h-2 rounded-full"
                          :style="ok ? 'background: var(--ds-green,#16a34a); box-shadow:0 0 6px var(--ds-green,#16a34a);' : 'background: var(--ds-amber,#d97706);'"></span>
                    <span x-text="ok ? 'Live' : (loading ? 'Loading…' : 'Stale')"></span>
                </span>
                <span class="text-xs" style="color:rgba(255,255,255,0.6);" x-text="updatedLabel"></span>
            </div>
        </div>
    </div>

    {{-- Row 1 — CPU + RAM --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

        {{-- CPU --}}
        <div class="rounded-md p-5" style="background: var(--surface); border:1px solid var(--border);">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--text-muted);">CPU</h2>
                <span class="text-xs" style="color: var(--text-muted);"><span x-text="fmt(d.cpu?.cores)"></span> cores</span>
            </div>
            <div class="flex items-end gap-4">
                <div class="flex-1">
                    <div class="text-3xl font-semibold" style="color: var(--text);">
                        <span x-text="fmt(d.cpu?.util1_pct)"></span><span class="text-lg" style="color:var(--text-muted);">%</span>
                    </div>
                    <div class="text-xs mt-0.5" style="color: var(--text-muted);">1-min load-based utilisation</div>
                </div>
                {{-- in-page sparkline (last ~40 samples of 1-min utilisation) --}}
                <svg width="150" height="42" viewBox="0 0 150 42" preserveAspectRatio="none" style="overflow:visible;">
                    <polyline :points="spark" fill="none" stroke="var(--brand-icon, #14b8a6)" stroke-width="1.5" />
                </svg>
            </div>
            <div class="mt-4" style="height:8px; background: var(--surface-alt); border-radius:2px; overflow:hidden;">
                <div :style="`width:${clampPct(d.cpu?.util1_pct)}%; height:100%; background:${utilColor(d.cpu?.util1_pct)}; transition:width .4s;`"></div>
            </div>
            <div class="grid grid-cols-3 gap-2 mt-3 text-center">
                <template x-for="k in ['load1','load5','load15']" :key="k">
                    <div class="rounded p-2" style="background: var(--surface-alt);">
                        <div class="text-sm font-mono" style="color: var(--text);" x-text="fmt(d.cpu?.[k])"></div>
                        <div class="text-[10px] uppercase tracking-wider" style="color: var(--text-muted);" x-text="k.replace('load','') + '-min'"></div>
                    </div>
                </template>
            </div>
        </div>

        {{-- RAM --}}
        <div class="rounded-md p-5" style="background: var(--surface); border:1px solid var(--border);">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Memory</h2>
                <span class="text-xs" style="color: var(--text-muted);"><span x-text="gb(d.memory?.total_mb)"></span> GB total</span>
            </div>
            <div class="text-3xl font-semibold" style="color: var(--text);">
                <span x-text="fmt(d.memory?.used_pct)"></span><span class="text-lg" style="color:var(--text-muted);">%</span>
                <span class="text-sm font-normal" style="color: var(--text-muted);">used</span>
            </div>
            <div class="mt-3" style="height:8px; background: var(--surface-alt); border-radius:2px; overflow:hidden;">
                <div :style="`width:${clampPct(d.memory?.used_pct)}%; height:100%; background:${utilColor(d.memory?.used_pct)}; transition:width .4s;`"></div>
            </div>
            <div class="grid grid-cols-3 gap-2 mt-3 text-center">
                <div class="rounded p-2" style="background: var(--surface-alt);">
                    <div class="text-sm font-mono" style="color: var(--text);"><span x-text="gb(d.memory?.used_mb)"></span></div>
                    <div class="text-[10px] uppercase tracking-wider" style="color: var(--text-muted);">Used GB</div>
                </div>
                <div class="rounded p-2" style="background: var(--surface-alt);">
                    <div class="text-sm font-mono" style="color: var(--text);"><span x-text="gb(d.memory?.available_mb)"></span></div>
                    <div class="text-[10px] uppercase tracking-wider" style="color: var(--text-muted);">Avail GB</div>
                </div>
                <div class="rounded p-2" style="background: var(--surface-alt);">
                    <div class="text-sm font-mono" style="color: var(--text);">
                        <span x-text="fmt(d.memory?.swap_used_pct)"></span><span class="text-xs">%</span>
                    </div>
                    <div class="text-[10px] uppercase tracking-wider" style="color: var(--text-muted);">Swap used</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Row 2 — Disk --}}
    <div class="rounded-md p-5" style="background: var(--surface); border:1px solid var(--border);">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Disk</h2>
            <span class="text-xs" style="color: var(--text-muted);">Amber ≥ {{ $diskAmber }}% · Red ≥ {{ $diskRed }}%</span>
        </div>
        <div class="space-y-4">
            <template x-for="disk in (d.disks || [])" :key="disk.path">
                <div>
                    <div class="flex items-center justify-between text-sm mb-1">
                        <span style="color: var(--text);"><span x-text="disk.label"></span>
                            <span class="text-xs font-mono ml-1" style="color: var(--text-muted);" x-text="disk.path"></span></span>
                        <span class="font-mono" style="color: var(--text);">
                            <span x-text="fmt(disk.used_gb)"></span> / <span x-text="fmt(disk.total_gb)"></span> GB
                            (<span x-text="fmt(disk.used_pct)"></span>%)
                        </span>
                    </div>
                    <div style="height:10px; background: var(--surface-alt); border-radius:2px; overflow:hidden;">
                        <div :style="`width:${clampPct(disk.used_pct)}%; height:100%; background:${diskColor(disk.state)}; transition:width .4s;`"></div>
                    </div>
                </div>
            </template>
            <div x-show="!d.disks || d.disks.length === 0" class="text-sm" style="color: var(--text-muted);">—</div>
        </div>
    </div>

    {{-- Row 3 — CoreX vitals --}}
    <div class="rounded-md p-5" style="background: var(--surface); border:1px solid var(--border);">
        <h2 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--text-muted);">CoreX Vitals</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">

            {{-- Queues --}}
            <div class="rounded p-3" style="background: var(--surface-alt);">
                <div class="text-xs uppercase tracking-wider mb-2" style="color: var(--text-muted);">Queue depth</div>
                <template x-for="q in (d.corex?.queues || [])" :key="q.queue">
                    <div class="flex items-center justify-between text-sm py-0.5">
                        <span style="color: var(--text);" x-text="q.queue"></span>
                        <span class="font-mono" :style="q.depth > 50 ? 'color: var(--ds-amber,#d97706);' : 'color: var(--text);'" x-text="q.depth"></span>
                    </div>
                </template>
                <div class="flex items-center justify-between text-sm py-0.5 mt-1 pt-1" style="border-top:1px solid var(--border);">
                    <span style="color: var(--text-muted);">oldest job</span>
                    <span class="font-mono" style="color: var(--text);" x-text="age(d.corex?.oldest_job_s)"></span>
                </div>
            </div>

            {{-- Jobs / failed --}}
            <div class="rounded p-3" style="background: var(--surface-alt);">
                <div class="text-xs uppercase tracking-wider mb-2" style="color: var(--text-muted);">Failed jobs</div>
                <div class="text-2xl font-semibold" :style="(d.corex?.failed_jobs > 0) ? 'color: var(--ds-amber,#d97706);' : 'color: var(--text);'"
                     x-text="fmt(d.corex?.failed_jobs)"></div>
                <div class="text-xs mt-1" style="color: var(--text-muted);">in failed_jobs table</div>
            </div>

            {{-- FPM pool --}}
            <div class="rounded p-3" style="background: var(--surface-alt);">
                <div class="text-xs uppercase tracking-wider mb-2" style="color: var(--text-muted);">php8.3-fpm pool</div>
                <div class="text-2xl font-semibold" style="color: var(--text);">
                    <span x-text="fmt(d.corex?.fpm?.active)"></span><span class="text-sm" style="color:var(--text-muted);"> active</span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-muted);">
                    <span x-text="fmt(d.corex?.fpm?.idle)"></span> idle · <span x-text="fmt(d.corex?.fpm?.total)"></span> workers
                </div>
            </div>

            {{-- MySQL --}}
            <div class="rounded p-3" style="background: var(--surface-alt);">
                <div class="text-xs uppercase tracking-wider mb-2" style="color: var(--text-muted);">MySQL connections</div>
                <div class="text-2xl font-semibold" style="color: var(--text);">
                    <span x-text="fmt(d.corex?.mysql?.connected)"></span>
                    <span class="text-sm" style="color:var(--text-muted);">/ <span x-text="fmt(d.corex?.mysql?.max)"></span></span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-muted);"><span x-text="fmt(d.corex?.mysql?.used_pct)"></span>% of max</div>
            </div>
        </div>

        {{-- Backups --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
            <div class="rounded p-3" style="background: var(--surface-alt);">
                <div class="text-xs uppercase tracking-wider mb-1" style="color: var(--text-muted);">Off-box backup (restic)</div>
                <div class="text-sm font-mono" style="color: var(--text);" x-text="fmt(d.backups?.offbox?.last_success)"></div>
                <div class="text-xs mt-1">
                    <span :style="d.backups?.offbox?.stale ? 'color: var(--ds-red,#dc2626);' : 'color: var(--ds-green,#16a34a);'"
                          x-text="d.backups?.offbox?.state ? d.backups.offbox.state : '—'"></span>
                    <span style="color: var(--text-muted);" x-show="d.backups?.offbox?.hours_since != null">
                        · <span x-text="fmt(d.backups?.offbox?.hours_since)"></span>h ago</span>
                </div>
            </div>
            <div class="rounded p-3" style="background: var(--surface-alt);">
                <div class="text-xs uppercase tracking-wider mb-1" style="color: var(--text-muted);">Latest local DB dump</div>
                <div class="text-sm font-mono" style="color: var(--text);" x-text="fmt(d.backups?.local_dump?.at)"></div>
                <div class="text-xs mt-1 truncate" style="color: var(--text-muted);" x-text="d.backups?.local_dump?.name || '—'"></div>
            </div>
            <div class="rounded p-3" style="background: var(--surface-alt);">
                <div class="text-xs uppercase tracking-wider mb-1" style="color: var(--text-muted);">Hetzner volume images</div>
                <div class="text-sm" style="color: var(--text);">Provider-side snapshots</div>
                <div class="text-xs mt-1" style="color: var(--text-muted);">daily ~22:13 UTC</div>
            </div>
        </div>
    </div>

    <p class="text-xs" style="color: var(--text-muted);">v1 — read-only, no historical storage. Values reflect the moment of the last poll.</p>
</div>

<script>
function serverHealth(cfg) {
    return {
        d: {},
        ok: false,
        loading: true,
        updatedLabel: '—',
        samples: [],
        spark: '',
        _timer: null,

        start() {
            this.tick();
            this._timer = setInterval(() => this.tick(), 10000);
            // stop polling when the tab is hidden; resume + refresh when visible
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) { clearInterval(this._timer); this._timer = null; }
                else if (!this._timer) { this.tick(); this._timer = setInterval(() => this.tick(), 10000); }
            });
        },

        async tick() {
            try {
                const r = await fetch(cfg.dataUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                if (!r.ok) throw new Error('http ' + r.status);
                this.d = await r.json();
                this.ok = true;
                this.updatedLabel = 'updated ' + new Date().toLocaleTimeString();
                const u = this.d?.cpu?.util1_pct;
                if (typeof u === 'number') {
                    this.samples.push(u);
                    if (this.samples.length > 40) this.samples.shift();
                    this.spark = this.buildSpark();
                }
            } catch (e) {
                this.ok = false; // header shows "Stale"; last-known values stay on screen
            } finally {
                this.loading = false;
            }
        },

        buildSpark() {
            const n = this.samples.length;
            if (n < 2) return '';
            const w = 150, h = 42, max = Math.max(20, ...this.samples);
            return this.samples.map((v, i) => {
                const x = (i / (n - 1)) * w;
                const y = h - (Math.min(v, max) / max) * h;
                return x.toFixed(1) + ',' + y.toFixed(1);
            }).join(' ');
        },

        // ── formatters / colour helpers (null-safe → "—") ──
        fmt(v) { return (v === null || v === undefined || v === '') ? '—' : v; },
        gb(mb) { return (mb === null || mb === undefined) ? '—' : (mb / 1024).toFixed(1); },
        clampPct(v) { return (typeof v === 'number') ? Math.max(0, Math.min(100, v)) : 0; },
        age(s) {
            if (s === null || s === undefined) return '—';
            if (s < 60) return s + 's';
            if (s < 3600) return Math.floor(s / 60) + 'm';
            return Math.floor(s / 3600) + 'h';
        },
        utilColor(v) {
            if (typeof v !== 'number') return 'var(--text-muted)';
            if (v >= 90) return 'var(--ds-red, #dc2626)';
            if (v >= 70) return 'var(--ds-amber, #d97706)';
            return 'var(--ds-green, #16a34a)';
        },
        diskColor(state) {
            if (state === 'red') return 'var(--ds-red, #dc2626)';
            if (state === 'amber') return 'var(--ds-amber, #d97706)';
            if (state === 'green') return 'var(--ds-green, #16a34a)';
            return 'var(--text-muted)';
        },
    };
}
</script>
@endsection
