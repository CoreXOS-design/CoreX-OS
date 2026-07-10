{{-- P24 Listing-Number repair tool (owner-only). Reads the original Property24
     CSV export and backfills the correct listing number (p24_ref) onto matching
     properties, with a live progress bar, so pushes update originals instead of
     creating duplicates. --}}
<div x-data="p24NumberFix()" class="contents">
    <button type="button" @click="show()"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all duration-300"
            style="background:rgba(255,255,255,0.08);color:#fff;border:1px solid rgba(255,255,255,0.18);"
            title="Backfill P24 listing numbers from the original P24 CSV export">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Fix P24 Numbers
    </button>

    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-[200] flex items-center justify-center p-4"
             style="background:rgba(0,0,0,0.6);" @keydown.escape.window="open && !running && (open=false)">
            <div class="w-full max-w-lg rounded-md overflow-hidden" style="background:var(--surface);border:1px solid var(--border);"
                 @click.outside="!running && (open=false)">
                {{-- Header --}}
                <div class="px-5 py-4 flex items-center justify-between" style="background:var(--brand-default,#0b2a4a);">
                    <div>
                        <h3 class="text-sm font-bold text-white">Fix P24 Listing Numbers</h3>
                        <p class="text-[0.6875rem] text-white/60">Backfill listing numbers from the original P24 CSV export.</p>
                    </div>
                    <button type="button" @click="!running && (open=false)" class="text-white/70 hover:text-white" :disabled="running">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="p-5 space-y-4">
                    {{-- Intro / safety note --}}
                    <p class="text-xs" style="color:var(--text-muted);">
                        Each row in the CSV is matched to a property by <strong>existing listing number</strong>, then
                        <strong>GPS</strong>, then <strong>street address</strong>. Only exact, single matches are written.
                        Run this <strong>before</strong> pushing to P24 so the push updates the original listing.
                    </p>

                    {{-- File picker --}}
                    <div x-show="!running && !done">
                        <label class="block text-[0.6875rem] font-bold uppercase tracking-wider mb-1.5" style="color:var(--text-muted);">Property24 CSV export</label>
                        <input type="file" accept=".csv,.txt" @change="pickFile($event)"
                               class="block w-full text-xs rounded-md file:mr-3 file:px-3 file:py-1.5 file:rounded-md file:border-0 file:text-xs file:font-semibold file:cursor-pointer"
                               style="color:var(--text-primary);">
                        <p class="text-[0.6875rem] mt-1.5" style="color:var(--text-muted);" x-show="file" x-cloak>
                            Selected: <span x-text="file && file.name"></span>
                        </p>
                    </div>

                    {{-- Error --}}
                    <div x-show="error" x-cloak class="rounded-md px-3 py-2 text-xs"
                         style="background:color-mix(in srgb, var(--ds-crimson, #c41e3a) 12%, transparent);color:var(--ds-crimson, #c41e3a);"
                         x-text="error"></div>

                    {{-- Progress --}}
                    <div x-show="running || done" x-cloak class="space-y-2">
                        <div class="flex items-center justify-between text-xs" style="color:var(--text-muted);">
                            <span x-text="running ? 'Processing…' : 'Done'"></span>
                            <span><span x-text="processed"></span> / <span x-text="total"></span> (<span x-text="pct"></span>%)</span>
                        </div>
                        <div class="h-2 w-full rounded-full overflow-hidden" style="background:var(--surface-2);">
                            <div class="h-full transition-all duration-200" :style="`width:${pct}%;background:var(--ds-green, #059669);`"></div>
                        </div>
                        {{-- Counters --}}
                        <div class="grid grid-cols-5 gap-2 pt-1 text-center">
                            <template x-for="c in counters" :key="c.key">
                                <div class="rounded-md py-1.5" style="background:var(--surface-2);">
                                    <div class="text-sm font-semibold" :style="`color:${c.color}`" x-text="stats[c.key]"></div>
                                    <div class="text-[0.5625rem] uppercase tracking-wider" style="color:var(--text-muted);" x-text="c.label"></div>
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Report (only what didn't cleanly apply) --}}
                    <div x-show="done && log.length" x-cloak class="space-y-1">
                        <p class="text-[0.6875rem] font-bold uppercase tracking-wider" style="color:var(--text-muted);">Needs attention</p>
                        <div class="max-h-40 overflow-y-auto rounded-md divide-y" style="background:var(--surface-2);border:1px solid var(--border);">
                            <template x-for="(r, i) in log" :key="i">
                                <div class="px-3 py-1.5 text-[0.6875rem] flex items-start gap-2" style="border-color:var(--border);">
                                    <span class="font-mono font-semibold flex-shrink-0" style="color:var(--text-primary);" x-text="'#' + (r.ln || '?')"></span>
                                    <span class="px-1.5 rounded text-[0.5625rem] uppercase tracking-wider flex-shrink-0"
                                          :style="r.status==='conflict' ? 'background:color-mix(in srgb,var(--ds-amber, #f59e0b) 18%,transparent);color:var(--ds-amber, #f59e0b)' : 'color:var(--text-muted)'"
                                          x-text="r.status"></span>
                                    <span style="color:var(--text-muted);" x-text="r.reason"></span>
                                </div>
                            </template>
                        </div>
                        <p class="text-[0.625rem]" style="color:var(--text-muted);">
                            Unmatched rows have no property in CoreX yet. Conflicts replaced a stale ref — withdraw that old duplicate on Property24.
                        </p>
                    </div>

                    {{-- Actions --}}
                    <div class="flex items-center justify-end gap-2 pt-1">
                        <button type="button" x-show="!running" @click="open=false"
                                class="px-3 py-1.5 rounded-md text-xs font-semibold"
                                style="background:var(--surface-2);color:var(--text-primary);border:1px solid var(--border);"
                                x-text="done ? 'Close' : 'Cancel'"></button>
                        <button type="button" x-show="!done" @click="run()" :disabled="running || !file"
                                class="px-4 py-1.5 rounded-md text-xs font-semibold transition-opacity"
                                :style="(running || !file) ? 'background:var(--surface-2);color:var(--text-muted);cursor:not-allowed;' : 'background:var(--ds-green, #059669);color:#fff;'">
                            <span x-show="!running">Run</span>
                            <span x-show="running" x-cloak>Working…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>

@once
<script>
function p24NumberFix() {
    return {
        open: false,
        file: null,
        running: false,
        done: false,
        error: '',
        token: null,
        total: 0,
        processed: 0,
        stats: { applied: 0, conflict: 0, skipped: 0, unmatched: 0, invalid: 0 },
        log: [],
        counters: [
            { key: 'applied',   label: 'Applied',   color: 'var(--ds-green, #059669)' },
            { key: 'conflict',  label: 'Conflict',  color: 'var(--ds-amber,#f59e0b)' },
            { key: 'unmatched', label: 'No match',  color: 'var(--text-muted)' },
            { key: 'skipped',   label: 'Skipped',   color: 'var(--text-muted)' },
            { key: 'invalid',   label: 'Invalid',   color: 'var(--text-muted)' },
        ],
        get csrf() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; },
        get pct() { return this.total ? Math.round((this.processed / this.total) * 100) : 0; },
        show() { this.reset(); this.file = null; this.open = true; },
        reset() {
            this.done = false; this.error = ''; this.token = null;
            this.total = 0; this.processed = 0; this.log = [];
            this.stats = { applied: 0, conflict: 0, skipped: 0, unmatched: 0, invalid: 0 };
        },
        pickFile(e) { this.file = (e.target.files && e.target.files[0]) || null; this.error = ''; },
        async run() {
            if (!this.file) { this.error = 'Choose the P24 CSV first.'; return; }
            this.reset(); this.running = true;
            try {
                const fd = new FormData();
                fd.append('file', this.file);
                const up = await fetch('{{ route('corex.properties.p24-fix.upload') }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd,
                });
                const uj = await up.json();
                if (!up.ok || !uj.ok) { this.error = uj.message || 'Upload failed.'; this.running = false; return; }
                this.token = uj.token;
                this.total = uj.total;

                let offset = 0;
                const limit = 25;
                while (true) {
                    const pr = await fetch('{{ route('corex.properties.p24-fix.process') }}', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ token: this.token, offset: offset, limit: limit }),
                    });
                    const pj = await pr.json();
                    if (!pr.ok || !pj.ok) { this.error = pj.message || 'Processing failed.'; this.running = false; return; }
                    this.processed = pj.processed;
                    this.total = pj.total;
                    this.stats = pj.stats;
                    if (pj.done) { this.log = pj.log || []; this.done = true; this.running = false; break; }
                    offset += limit;
                }
            } catch (e) {
                this.error = 'Network error: ' + e.message;
                this.running = false;
            }
        },
    };
}
</script>
@endonce
