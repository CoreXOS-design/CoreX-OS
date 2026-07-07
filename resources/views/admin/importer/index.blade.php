{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">P24 Importer</h1>
                <p class="text-sm text-white/60">Import Property24 agents, listings and images for a selected agency.</p>
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
    @if ($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: var(--ds-crimson);">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.48 0L3.16 16.25A2 2 0 005 19z"/>
            </svg>
            <div class="flex-1 space-y-0.5">
                @foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach
            </div>
        </div>
    @endif

    {{-- Target Agency picker --}}
    <form method="GET" class="rounded-md p-4 flex items-end gap-3"
          style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex-1">
            <label for="agency_id" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Target Agency</label>
            <select id="agency_id" name="agency_id" onchange="this.form.submit()"
                    class="w-full rounded-md px-3 py-2 text-sm"
                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">— Select Agency —</option>
                @foreach ($agencies as $a)
                    <option value="{{ $a->id }}" @selected($activeAgencyId === $a->id)>{{ $a->name }}</option>
                @endforeach
            </select>
        </div>
    </form>

    {{-- Upload cards --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Agents card --}}
        <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);" x-data="importerUpload()">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-lg font-semibold" style="color: var(--text-primary);">1. Agents</h3>
                <span class="ds-badge ds-badge-info">Stage 1</span>
            </div>
            <form method="POST" action="{{ route('admin.importer.agents.upload') }}" enctype="multipart/form-data" class="space-y-3" @submit.prevent="submit($event)">
                @csrf
                <input type="hidden" name="agency_id" value="{{ $activeAgencyId }}">
                <label class="block text-xs font-medium" style="color: var(--text-secondary);">Agents CSV (Agency-{AgencyId}-export-agents.csv)</label>
                <input type="file" name="agents_csv" required accept=".csv,text/csv"
                       :disabled="phase !== 'idle' && phase !== 'error'"
                       class="block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:px-3 file:py-1.5 file:text-sm"
                       style="color: var(--text-secondary);">
                <button type="submit"
                        class="corex-btn-primary"
                        :disabled="!{{ $activeAgencyId ? 'true' : 'false' }} || (phase !== 'idle' && phase !== 'error')">
                    <span x-show="phase === 'idle' || phase === 'error'">Parse &amp; Preview</span>
                    <span x-show="phase === 'uploading'" x-cloak>Uploading…</span>
                    <span x-show="phase === 'parsing'" x-cloak>Parsing CSV…</span>
                    <span x-show="phase === 'done'" x-cloak>Redirecting…</span>
                </button>
                @if (!$activeAgencyId)
                    <div class="text-xs" style="color: var(--ds-amber);">Select a Target Agency first.</div>
                @endif

                @include('admin.importer.partials.upload-progress')
            </form>
        </div>

        {{-- Listings + Images card --}}
        <div class="rounded-md p-5 {{ !$hasAgentsRun ? 'opacity-60' : '' }}" style="background: var(--surface); border: 1px solid var(--border);" x-data="importerUpload()">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-lg font-semibold" style="color: var(--text-primary);">2. Listings &amp; Images</h3>
                <span class="ds-badge ds-badge-info">Stage 2</span>
            </div>
            @if (!$hasAgentsRun)
                <div class="rounded-md px-3 py-2 text-xs mb-3"
                     style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent);
                            border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);
                            color: var(--text-primary);">
                    Import agents for this agency first so listings can be linked.
                </div>
            @endif
            <form method="POST" action="{{ route('admin.importer.listings.upload') }}" enctype="multipart/form-data" class="space-y-3" @submit.prevent="submit($event)">
                @csrf
                <input type="hidden" name="agency_id" value="{{ $activeAgencyId }}">
                <label class="block text-xs font-medium" style="color: var(--text-secondary);">Listings CSV</label>
                <input type="file" name="listings_csv" required accept=".csv,text/csv"
                       :disabled="phase !== 'idle' && phase !== 'error'"
                       class="block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:px-3 file:py-1.5 file:text-sm"
                       style="color: var(--text-secondary);">
                <label class="block text-xs font-medium" style="color: var(--text-secondary);">Images CSV</label>
                <input type="file" name="images_csv" required accept=".csv,text/csv"
                       :disabled="phase !== 'idle' && phase !== 'error'"
                       class="block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:px-3 file:py-1.5 file:text-sm"
                       style="color: var(--text-secondary);">
                <p class="text-xs" style="color: var(--text-muted);">Images are matched to Listings by ListingNumber.</p>

                <label class="flex items-start gap-2 cursor-pointer rounded-md px-3 py-2"
                       style="background: var(--surface-2); border: 1px solid var(--border);">
                    <input type="checkbox" name="mark_compliant_on_confirm" value="1" checked class="mt-0.5">
                    <span class="text-xs" style="color: var(--text-secondary);">
                        <span class="font-semibold" style="color: var(--text-primary);">Mark all imported properties as compliant</span>
                        <span class="block mt-0.5" style="color: var(--text-muted);">
                            Use for agency go-live migrations only — pre-existing P24 stock is treated as already compliant so it can be marketed immediately on CoreX. Leave unticked for fresh imports that still need to pass FICA / mandate / photo gates.
                        </span>
                    </span>
                </label>

                <button type="submit"
                        class="corex-btn-primary"
                        :disabled="!{{ ($activeAgencyId && $hasAgentsRun) ? 'true' : 'false' }} || (phase !== 'idle' && phase !== 'error')">
                    <span x-show="phase === 'idle' || phase === 'error'">Parse &amp; Send to Review Queue</span>
                    <span x-show="phase === 'uploading'" x-cloak>Uploading…</span>
                    <span x-show="phase === 'parsing'" x-cloak>Parsing CSVs…</span>
                    <span x-show="phase === 'done'" x-cloak>Redirecting…</span>
                </button>

                @include('admin.importer.partials.upload-progress')
            </form>
        </div>
    </div>

    <script>
        function importerUpload() {
            return {
                phase: 'idle',
                progress: 0,
                error: null,
                bytesSent: 0,
                bytesTotal: 0,
                submit(event) {
                    const form = event.target;
                    const formData = new FormData(form);
                    this.phase = 'uploading';
                    this.progress = 0;
                    this.error = null;
                    this.bytesSent = 0;
                    this.bytesTotal = 0;

                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', form.action, true);
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                    xhr.setRequestHeader('Accept', 'application/json');

                    xhr.upload.onprogress = (e) => {
                        if (e.lengthComputable) {
                            this.bytesTotal = e.total;
                            this.bytesSent = e.loaded;
                            this.progress = Math.round((e.loaded / e.total) * 100);
                        }
                    };
                    xhr.upload.onload = () => {
                        this.progress = 100;
                        this.phase = 'parsing';
                    };
                    xhr.onload = () => {
                        let data = {};
                        try { data = JSON.parse(xhr.responseText || '{}'); } catch (_) {}
                        if (xhr.status >= 200 && xhr.status < 300 && data.redirect) {
                            this.phase = 'done';
                            window.location = data.redirect;
                            return;
                        }
                        this.phase = 'error';
                        if (data.errors) {
                            this.error = Object.values(data.errors).flat().join(' · ');
                        } else if (data.message) {
                            this.error = data.message;
                        } else {
                            this.error = 'Upload failed (HTTP ' + xhr.status + ')';
                        }
                    };
                    xhr.onerror = () => {
                        this.phase = 'error';
                        this.error = 'Network error — check your connection and try again.';
                    };
                    xhr.ontimeout = () => {
                        this.phase = 'error';
                        this.error = 'Upload timed out.';
                    };
                    xhr.send(formData);
                },
                formatBytes(n) {
                    if (!n) return '0 B';
                    const units = ['B','KB','MB','GB'];
                    let i = 0; let v = n;
                    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
                    return v.toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
                }
            };
        }
    </script>

    {{-- History --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
            <h3 class="text-lg font-semibold" style="color: var(--text-primary);">Recent Import Runs</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Run #</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agency</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Kind</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Counts</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">User</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Created</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($runs as $r)
                    @php
                        $statusVariant = match($r->status) {
                            'completed' => 'ds-badge-success',
                            'failed' => 'ds-badge-danger',
                            'pending_confirm', 'parsing' => 'ds-badge-warning',
                            default => 'ds-badge-default',
                        };
                    @endphp
                    <tr class="transition-colors" style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-3 font-mono" style="color: var(--text-primary);">#{{ $r->id }}</td>
                        <td class="px-4 py-3" style="color: var(--text-primary);">{{ $r->agency?->name ?? '—' }}</td>
                        <td class="px-4 py-3" style="color: var(--text-primary);">{{ $r->kind }}</td>
                        <td class="px-4 py-3">
                            <span class="ds-badge {{ $statusVariant }}">{{ str_replace('_', ' ', $r->status) }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">
                            <div class="flex flex-wrap gap-x-2 gap-y-1">
                                @foreach (($r->counts_json ?? []) as $k => $v)
                                    <span>{{ $k }}={{ is_array($v) ? number_format(count($v)) : (is_numeric($v) ? number_format($v) : $v) }}</span>
                                @endforeach
                            </div>
                        </td>
                        <td class="px-4 py-3" style="color: var(--text-primary);">{{ $r->user?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">{{ $r->created_at?->diffForHumans() }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.importer.show', $r) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Open</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                            No import runs yet.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
