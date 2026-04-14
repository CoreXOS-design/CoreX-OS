@extends('layouts.corex')

@section('corex-content')
<div class="max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6"
     x-data="importerReview()">

    <div class="rounded-md px-6 py-4 flex items-center justify-between" style="background:var(--brand-default, #0b2a4a);">
        <div>
            <h2 class="text-xl font-bold text-white">Property Review &amp; Share</h2>
            <div class="text-sm mt-0.5" style="color:rgba(255,255,255,0.6);">
                Confirm imported listings and copy the public property page link for each agency.
            </div>
        </div>
    </div>

    {{-- Shareable public links — one per agency with a slug --}}
    <div class="rounded-md bg-surface p-4 border border-subtle/30">
        <div class="text-xs font-semibold uppercase tracking-wide text-muted mb-2">Public property pages</div>
        <div class="space-y-2">
            @forelse ($agencies as $a)
                @if(!empty($a->slug))
                    @php $publicUrl = url('/' . $a->slug . '/properties'); @endphp
                    <div class="flex items-center gap-3 flex-wrap">
                        <div class="text-sm font-medium min-w-[180px]">{{ $a->name }}</div>
                        <code class="text-xs bg-surface-2 border border-subtle/30 rounded px-2 py-1 flex-1 min-w-[280px] truncate">{{ $publicUrl }}</code>
                        <button type="button"
                                onclick="navigator.clipboard.writeText('{{ $publicUrl }}'); this.innerText='Copied ✓'; setTimeout(()=>this.innerText='Copy link', 1500);"
                                class="rounded-md px-3 py-1.5 text-xs bg-surface-2 border border-subtle hover:border-subtle/60">
                            Copy link
                        </button>
                        <a href="{{ $publicUrl }}" target="_blank"
                           class="rounded-md px-3 py-1.5 text-xs text-white"
                           style="background:var(--brand-button, #0ea5e9);">
                            Open
                        </a>
                    </div>
                @endif
            @empty
                <div class="text-sm text-muted">No agencies found.</div>
            @endforelse
        </div>
    </div>

    {{-- Filters --}}
    <form method="GET" class="rounded-md bg-surface p-4 sticky top-0 z-10 border border-subtle/30">
        <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
            <select name="agency_id" class="rounded-md bg-surface-2 border border-subtle px-2 py-1.5 text-sm">
                <option value="">All agencies</option>
                @foreach ($agencies as $a)
                    <option value="{{ $a->id }}" @selected(request('agency_id') == $a->id)>{{ $a->name }}</option>
                @endforeach
            </select>
            <select name="run_id" class="rounded-md bg-surface-2 border border-subtle px-2 py-1.5 text-sm">
                <option value="">All runs</option>
                @foreach ($runs as $r)
                    <option value="{{ $r->id }}" @selected(request('run_id') == $r->id)>Run #{{ $r->id }} ({{ $r->created_at->format('Y-m-d') }})</option>
                @endforeach
            </select>
            <select name="listing_type" class="rounded-md bg-surface-2 border border-subtle px-2 py-1.5 text-sm">
                <option value="all">All types</option>
                <option value="Sale" @selected(request('listing_type') === 'Sale')>Sale</option>
                <option value="Rental" @selected(request('listing_type') === 'Rental')>Rental</option>
            </select>
            <select name="status" class="rounded-md bg-surface-2 border border-subtle px-2 py-1.5 text-sm">
                @foreach (['pending','confirmed','excluded','error','all'] as $s)
                    <option value="{{ $s }}" @selected((request('status') ?? 'pending') === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
            <select name="has_errors" class="rounded-md bg-surface-2 border border-subtle px-2 py-1.5 text-sm">
                <option value="">Errors: any</option>
                <option value="yes" @selected(request('has_errors') === 'yes')>With errors</option>
                <option value="no" @selected(request('has_errors') === 'no')>No errors</option>
            </select>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search address / listing#"
                   class="rounded-md bg-surface-2 border border-subtle px-2 py-1.5 text-sm">
        </div>
        <div class="flex items-center justify-between mt-3">
            <div class="flex items-center gap-2">
                <button type="button" @click="bulkConfirm()"
                        class="rounded-md px-3 py-1.5 text-xs text-white"
                        style="background:var(--brand-button, #0ea5e9);">
                    Confirm selected
                </button>
                <button type="button" @click="bulkExclude()"
                        class="rounded-md px-3 py-1.5 text-xs bg-surface-2 border border-subtle">
                    Exclude selected
                </button>
                <span class="text-xs text-muted" x-text="selected.length + ' selected'"></span>
            </div>
            <button type="submit" class="rounded-md px-3 py-1.5 text-xs bg-surface-2 border border-subtle">Apply Filters</button>
        </div>
    </form>

    {{-- Table --}}
    <div class="rounded-md bg-surface">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs uppercase text-muted border-b border-subtle">
                <tr>
                    <th class="px-2 py-2"><input type="checkbox" @change="toggleAll($event)"></th>
                    <th class="px-2 py-2 text-left">Listing #</th>
                    <th class="px-2 py-2 text-left">Address</th>
                    <th class="px-2 py-2 text-left">Type</th>
                    <th class="px-2 py-2 text-left">Price</th>
                    <th class="px-2 py-2 text-left">Beds/Baths</th>
                    <th class="px-2 py-2 text-left">Agent</th>
                    <th class="px-2 py-2 text-left">Images</th>
                    <th class="px-2 py-2 text-left">Errors</th>
                    <th class="px-2 py-2 text-left">Status</th>
                    <th class="px-2 py-2 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                @php
                    $m = $row->mapped_json ?? [];
                    $errs = (array) ($row->errors_json ?? []);
                @endphp
                <tr class="border-b border-subtle/40 {{ !empty($errs) ? 'bg-red-500/5' : '' }}">
                    <td class="px-2 py-2">
                        <input type="checkbox" :value="{{ $row->id }}" @change="toggleRow({{ $row->id }}, $event)">
                    </td>
                    <td class="px-2 py-2 font-mono text-xs">{{ $row->external_id }}</td>
                    <td class="px-2 py-2">{{ $m['address'] ?? '—' }}</td>
                    <td class="px-2 py-2">{{ $m['listing_type'] ?? '' }}</td>
                    <td class="px-2 py-2">
                        @if (!empty($m['price'])) R {{ number_format((float)$m['price'], 0, '.', ',') }}
                        @elseif (!empty($m['rental_amount'])) R {{ number_format((float)$m['rental_amount'], 0, '.', ',') }} /m
                        @else — @endif
                    </td>
                    <td class="px-2 py-2 text-xs">{{ $m['beds'] ?? 0 }}b / {{ $m['baths'] ?? 0 }}ba</td>
                    <td class="px-2 py-2">
                        @if ($row->resolvedAgent)
                            {{ $row->resolvedAgent->name }}
                        @else
                            <span class="text-red-400 text-xs">unresolved</span>
                        @endif
                    </td>
                    <td class="px-2 py-2 text-xs">{{ count((array)$row->image_urls_json) }}</td>
                    <td class="px-2 py-2">
                        @if (!empty($errs))
                            <span class="px-2 py-0.5 rounded-md text-xs bg-red-500/20 text-red-300">{{ count($errs) }}</span>
                        @else — @endif
                    </td>
                    <td class="px-2 py-2">
                        <span class="px-2 py-0.5 rounded-md text-xs bg-surface-2">{{ $row->status }}</span>
                    </td>
                    <td class="px-2 py-2 text-right whitespace-nowrap">
                        <button type="button" @click="openDrawer({{ $row->id }})" class="text-xs mr-2" style="color:var(--brand-icon);">Details</button>
                        @if ($row->status === 'pending')
                            <button type="button" @click="confirmRow({{ $row->id }})" class="text-xs mr-2" style="color:var(--brand-icon);">Confirm</button>
                            <button type="button" @click="excludeRow({{ $row->id }})" class="text-xs text-red-400">Exclude</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="11" class="py-10 text-center text-muted">No listings pending review. Start a new import from Admin → P24 Importer.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
        <div class="p-4">{{ $rows->links() }}</div>
    </div>

    {{-- Drawer --}}
    <div x-show="drawerOpen" x-cloak
         class="fixed inset-0 z-40 flex"
         @keydown.escape.window="drawerOpen = false">
        <div class="fixed inset-0 bg-black/50" @click="drawerOpen = false"></div>
        <div class="relative ml-auto w-full max-w-2xl bg-surface h-full overflow-y-auto p-6"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0">
            <button @click="drawerOpen = false" class="absolute top-3 right-3 text-muted">✕</button>
            <div x-html="drawerHtml"></div>
        </div>
    </div>
</div>

<script>
function importerReview() {
    return {
        selected: [],
        drawerOpen: false,
        drawerHtml: '',
        toggleRow(id, e) {
            if (e.target.checked) this.selected.push(id);
            else this.selected = this.selected.filter(x => x !== id);
        },
        toggleAll(e) {
            const boxes = document.querySelectorAll('tbody input[type=checkbox]');
            this.selected = [];
            boxes.forEach(b => {
                b.checked = e.target.checked;
                if (b.checked) this.selected.push(parseInt(b.value));
            });
        },
        async openDrawer(id) {
            const res = await fetch(`/admin/importer/rows/${id}`, { headers: {Accept: 'text/html'} });
            this.drawerHtml = await res.text();
            this.drawerOpen = true;
        },
        async confirmRow(id) {
            await this.post(`/admin/importer/rows/${id}/confirm`);
            location.reload();
        },
        async excludeRow(id) {
            if (!confirm('Exclude this row?')) return;
            await this.post(`/admin/importer/rows/${id}/exclude`);
            location.reload();
        },
        async bulkConfirm() {
            if (!this.selected.length) return;
            if (!confirm(`Confirm ${this.selected.length} rows?`)) return;
            await this.post('/admin/importer/rows/bulk/confirm', {ids: this.selected});
            location.reload();
        },
        async bulkExclude() {
            if (!this.selected.length) return;
            if (!confirm(`Exclude ${this.selected.length} rows?`)) return;
            await this.post('/admin/importer/rows/bulk/exclude', {ids: this.selected});
            location.reload();
        },
        async post(url, body = {}) {
            return fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                },
                body: JSON.stringify(body),
            });
        },
    };
}
</script>
@endsection
