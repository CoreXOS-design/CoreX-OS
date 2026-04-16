@extends('layouts.onboarding-portal')

@section('portal-content')
<div class="max-w-[1400px] mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-4"
     x-data="portalReview('{{ $portal->token }}')"
     x-init="startPolling()">

    {{-- Filters --}}
    <form method="GET" class="rounded-md bg-surface p-4 border border-subtle/30 sticky top-0 z-10">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <select name="status" class="rounded-md bg-surface-2 border border-subtle px-2 py-1.5 text-sm">
                @foreach (['pending','confirmed','excluded','error','all'] as $s)
                    <option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
            <select name="listing_type" class="rounded-md bg-surface-2 border border-subtle px-2 py-1.5 text-sm">
                <option value="all" @selected($type === 'all')>All types</option>
                <option value="Sale" @selected($type === 'Sale')>Sale</option>
                <option value="Rental" @selected($type === 'Rental')>Rental</option>
            </select>
            <input type="text" name="search" value="{{ $search }}" placeholder="Search address / listing #"
                   class="rounded-md bg-surface-2 border border-subtle px-2 py-1.5 text-sm">
            <button type="submit" class="rounded-md px-3 py-1.5 text-xs bg-surface-2 border border-subtle">Apply</button>
        </div>
        <div class="flex items-center justify-between mt-3 flex-wrap gap-2">
            <div class="flex items-center gap-2">
                <button type="button" @click="bulkConfirm()"
                        class="portal-cta rounded-md px-3 py-1.5 text-xs font-semibold">
                    Confirm selected
                </button>
                <button type="button" @click="bulkExclude()"
                        class="rounded-md px-3 py-1.5 text-xs bg-surface-2 border border-subtle">
                    Exclude selected
                </button>
                <button type="button" @click="confirmAllPending()"
                        class="rounded-md px-3 py-1.5 text-xs bg-surface-2 border border-subtle">
                    Confirm all pending ({{ $rows->total() }})
                </button>
                <span class="text-xs text-muted" x-text="selected.length + ' selected'"></span>
            </div>
            <a href="{{ route('onboarding.portal.finish', $portal->token) }}"
               class="rounded-md px-3 py-1.5 text-xs border border-subtle">
                Finish review →
            </a>
        </div>
    </form>

    {{-- Summary --}}
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 text-xs">
        <div class="rounded-md bg-surface p-2 text-center"><span class="text-muted">Pending</span> <span x-text="counts.pending" class="font-semibold ml-1">{{ $counts['pending'] }}</span></div>
        <div class="rounded-md bg-surface p-2 text-center"><span class="text-muted">In progress</span> <span x-text="counts.processing" class="font-semibold ml-1">{{ $counts['processing'] }}</span></div>
        <div class="rounded-md bg-surface p-2 text-center"><span class="text-muted">Confirmed</span> <span x-text="counts.confirmed" class="font-semibold ml-1">{{ $counts['confirmed'] }}</span></div>
        <div class="rounded-md bg-surface p-2 text-center"><span class="text-muted">Excluded</span> <span x-text="counts.excluded" class="font-semibold ml-1">{{ $counts['excluded'] }}</span></div>
        <div class="rounded-md bg-surface p-2 text-center"><span class="text-muted">Errors</span> <span x-text="counts.error" class="font-semibold ml-1">{{ $counts['error'] }}</span></div>
    </div>

    {{-- Table --}}
    <div class="rounded-md bg-surface border border-subtle/30">
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
                    <th class="px-2 py-2 text-left">Photos</th>
                    <th class="px-2 py-2 text-left">Status</th>
                    <th class="px-2 py-2 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                @php
                    $m = $row->mapped_json ?? [];
                    $errs = (array) ($row->errors_json ?? []);
                    $isProcessing = $row->isProcessing();
                @endphp
                <tr class="border-b border-subtle/40" :class="processing[{{ $row->id }}] ? 'opacity-60' : ''" data-row="{{ $row->id }}">
                    <td class="px-2 py-2">
                        @if ($row->status === 'pending' && !$isProcessing)
                            <input type="checkbox" value="{{ $row->id }}" @change="toggleRow({{ $row->id }}, $event)">
                        @endif
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
                        <select class="rounded-md bg-surface-2 border border-subtle px-1 py-0.5 text-xs"
                                @change="reassignAgent({{ $row->id }}, $event.target.value)">
                            <option value="">— unassigned —</option>
                            @foreach ($agents as $a)
                                <option value="{{ $a->id }}" @selected($row->resolved_agent_id == $a->id)>{{ $a->name }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td class="px-2 py-2 text-xs">{{ count((array)$row->image_urls_json) }}</td>
                    <td class="px-2 py-2">
                        @if ($isProcessing)
                            <span class="px-2 py-0.5 rounded-md text-xs bg-amber-500/20 text-amber-700">processing…</span>
                        @elseif (!empty($errs))
                            <span class="px-2 py-0.5 rounded-md text-xs bg-red-500/20 text-red-700" title="{{ implode('; ', $errs) }}">error</span>
                        @else
                            <span class="px-2 py-0.5 rounded-md text-xs bg-surface-2">{{ $row->status }}</span>
                        @endif
                    </td>
                    <td class="px-2 py-2 text-right whitespace-nowrap">
                        @if (in_array($row->status, ['pending','error']) && !$isProcessing)
                            <button type="button" @click="confirmRow({{ $row->id }})"
                                    class="portal-accent text-xs mr-2 font-semibold" :disabled="processing[{{ $row->id }}]">Confirm</button>
                            <button type="button" @click="excludeRow({{ $row->id }})"
                                    class="text-xs text-red-500">Exclude</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="py-10 text-center text-muted">No listings match your filters.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
        <div class="p-4">{{ $rows->links() }}</div>
    </div>
</div>

<script>
function portalReview(token) {
    return {
        selected: [],
        processing: {},
        counts: @json($counts),
        pollTimer: null,
        csrf: document.querySelector('meta[name=csrf-token]')?.content ?? '',

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
        async post(url, body = {}) {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrf,
                },
                body: JSON.stringify(body),
            });
            return res.json();
        },
        async confirmRow(id) {
            this.processing[id] = true;
            await this.post(`/onboarding/${token}/rows/${id}/confirm`);
            this.startPolling();
        },
        async excludeRow(id) {
            if (!confirm('Exclude this listing from going live?')) return;
            await this.post(`/onboarding/${token}/rows/${id}/exclude`);
            location.reload();
        },
        async reassignAgent(id, userId) {
            if (!userId) return;
            const r = await this.post(`/onboarding/${token}/rows/${id}/reassign`, {user_id: parseInt(userId)});
            if (!r.ok) alert('Could not reassign agent.');
        },
        async bulkConfirm() {
            if (!this.selected.length) return;
            if (!confirm(`Confirm ${this.selected.length} listings?`)) return;
            this.selected.forEach(id => this.processing[id] = true);
            await this.post(`/onboarding/${token}/rows/bulk/confirm`, {ids: this.selected});
            this.selected = [];
            this.startPolling();
        },
        async bulkExclude() {
            if (!this.selected.length) return;
            if (!confirm(`Exclude ${this.selected.length} listings?`)) return;
            await this.post(`/onboarding/${token}/rows/bulk/exclude`, {ids: this.selected});
            location.reload();
        },
        async confirmAllPending() {
            if (!confirm('Confirm every pending listing? This cannot be undone easily.')) return;
            await this.post(`/onboarding/${token}/rows/confirm-all`);
            this.startPolling();
        },
        startPolling() {
            if (this.pollTimer) return;
            this.pollTimer = setInterval(async () => {
                const res = await fetch(`/onboarding/${token}/status`, {headers:{Accept:'application/json'}});
                const data = await res.json();
                this.counts = data.counts;
                if (data.counts.processing === 0) {
                    clearInterval(this.pollTimer);
                    this.pollTimer = null;
                    // Reflect finished work in the table
                    if (Object.keys(this.processing).length) {
                        location.reload();
                    }
                }
            }, 3000);
        },
    };
}
</script>
@endsection
