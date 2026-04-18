@extends('layouts.corex')

@section('corex-content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    {{-- Header --}}
    <div class="rounded-md px-6 py-4 flex items-center justify-between" style="background:var(--brand-default, #0b2a4a);">
        <div>
            <h2 class="text-xl font-bold text-white">P24 Importer</h2>
            <div class="text-sm mt-0.5" style="color:rgba(255,255,255,0.6);">
                Import Property24 agents, listings and images for a selected agency.
            </div>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 px-4 py-2 text-sm">
            {{ session('status') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="rounded-md bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-2 text-sm">
            @foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach
        </div>
    @endif

    {{-- Target Agency picker (top) --}}
    <form method="GET" class="rounded-md bg-surface p-4 flex items-end gap-3">
        <div class="flex-1">
            <label class="text-xs text-muted block mb-1">Target Agency</label>
            <select name="agency_id" onchange="this.form.submit()"
                    class="w-full rounded-md bg-surface-2 border border-subtle px-3 py-2 text-sm">
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
        <div class="rounded-md bg-surface p-5" x-data="importerUpload()">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-base font-semibold">1. Agents</h3>
                <span class="text-xs text-muted">Stage 1</span>
            </div>
            <form method="POST" action="{{ route('admin.importer.agents.upload') }}" enctype="multipart/form-data" class="space-y-3" @submit.prevent="submit($event)">
                @csrf
                <input type="hidden" name="agency_id" value="{{ $activeAgencyId }}">
                <label class="block text-xs text-muted">Agents CSV (Agency-{AgencyId}-export-agents.csv)</label>
                <input type="file" name="agents_csv" required accept=".csv,text/csv"
                       :disabled="phase !== 'idle' && phase !== 'error'"
                       class="block w-full text-sm text-muted file:mr-3 file:rounded-md file:border-0 file:bg-surface-2 file:px-3 file:py-1.5 file:text-sm file:text-inherit">
                <button type="submit"
                        class="rounded-md px-4 py-2 text-sm font-medium text-white transition-colors duration-300 disabled:opacity-50"
                        style="background:var(--brand-button, #0ea5e9);"
                        :disabled="!{{ $activeAgencyId ? 'true' : 'false' }} || (phase !== 'idle' && phase !== 'error')">
                    <span x-show="phase === 'idle' || phase === 'error'">Parse &amp; Preview</span>
                    <span x-show="phase === 'uploading'" x-cloak>Uploading…</span>
                    <span x-show="phase === 'parsing'" x-cloak>Parsing CSV…</span>
                    <span x-show="phase === 'done'" x-cloak>Redirecting…</span>
                </button>
                @if (!$activeAgencyId)
                    <div class="text-xs text-amber-400">Select a Target Agency first.</div>
                @endif

                @include('admin.importer.partials.upload-progress')
            </form>
        </div>

        {{-- Listings + Images card --}}
        <div class="rounded-md bg-surface p-5 {{ !$hasAgentsRun ? 'opacity-60' : '' }}" x-data="importerUpload()">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-base font-semibold">2. Listings &amp; Images</h3>
                <span class="text-xs text-muted">Stage 2</span>
            </div>
            @if (!$hasAgentsRun)
                <div class="text-xs text-amber-400 mb-3">
                    Import agents for this agency first so listings can be linked.
                </div>
            @endif
            <form method="POST" action="{{ route('admin.importer.listings.upload') }}" enctype="multipart/form-data" class="space-y-3" @submit.prevent="submit($event)">
                @csrf
                <input type="hidden" name="agency_id" value="{{ $activeAgencyId }}">
                <label class="block text-xs text-muted">Listings CSV</label>
                <input type="file" name="listings_csv" required accept=".csv,text/csv"
                       :disabled="phase !== 'idle' && phase !== 'error'"
                       class="block w-full text-sm text-muted file:mr-3 file:rounded-md file:border-0 file:bg-surface-2 file:px-3 file:py-1.5 file:text-sm file:text-inherit">
                <label class="block text-xs text-muted">Images CSV</label>
                <input type="file" name="images_csv" required accept=".csv,text/csv"
                       :disabled="phase !== 'idle' && phase !== 'error'"
                       class="block w-full text-sm text-muted file:mr-3 file:rounded-md file:border-0 file:bg-surface-2 file:px-3 file:py-1.5 file:text-sm file:text-inherit">
                <div class="text-xs text-muted">Images are matched to Listings by ListingNumber.</div>
                <button type="submit"
                        class="rounded-md px-4 py-2 text-sm font-medium text-white transition-colors duration-300 disabled:opacity-50"
                        style="background:var(--brand-button, #0ea5e9);"
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
    <div class="rounded-md bg-surface p-5">
        <h3 class="text-base font-semibold mb-3">Recent Import Runs</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs uppercase text-muted border-b border-subtle">
                    <tr>
                        <th class="text-left py-2 px-2">Run #</th>
                        <th class="text-left py-2 px-2">Agency</th>
                        <th class="text-left py-2 px-2">Kind</th>
                        <th class="text-left py-2 px-2">Status</th>
                        <th class="text-left py-2 px-2">Counts</th>
                        <th class="text-left py-2 px-2">User</th>
                        <th class="text-left py-2 px-2">Created</th>
                        <th class="text-right py-2 px-2">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($runs as $r)
                    <tr class="border-b border-subtle/40">
                        <td class="py-2 px-2 font-mono">#{{ $r->id }}</td>
                        <td class="py-2 px-2">{{ $r->agency?->name }}</td>
                        <td class="py-2 px-2">{{ $r->kind }}</td>
                        <td class="py-2 px-2">
                            <span class="px-2 py-0.5 rounded-md text-xs bg-surface-2">{{ $r->status }}</span>
                        </td>
                        <td class="py-2 px-2 text-xs text-muted">
                            @foreach (($r->counts_json ?? []) as $k => $v)
                                <span>{{ $k }}={{ is_array($v) ? count($v) : $v }}</span>
                            @endforeach
                        </td>
                        <td class="py-2 px-2">{{ $r->user?->name }}</td>
                        <td class="py-2 px-2 text-xs text-muted">{{ $r->created_at?->diffForHumans() }}</td>
                        <td class="py-2 px-2 text-right">
                            <a href="{{ route('admin.importer.show', $r) }}" class="text-sm" style="color:var(--brand-icon);">Open</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-6 text-center text-muted">No import runs yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
