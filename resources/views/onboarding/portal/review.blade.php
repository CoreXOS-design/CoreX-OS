@extends('layouts.onboarding-portal')

@section('portal-content')
<div class="max-w-[1400px] mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-4"
     x-data="portalReview('{{ $portal->urlKey() }}')">

    {{-- Filters --}}
    <form method="GET" class="rounded-md bg-surface p-4 border border-subtle/30 sticky top-0 z-10">
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
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
            <select name="per_page" onchange="this.form.submit()"
                    class="rounded-md bg-surface-2 border border-subtle px-2 py-1.5 text-sm" title="Listings per page">
                @foreach ([15, 30, 50, 100, 200] as $pp)
                    <option value="{{ $pp }}" @selected((int) $perPage === $pp)>{{ $pp }} per page</option>
                @endforeach
            </select>
            <input type="text" name="search" value="{{ $search }}" placeholder="Search address / listing # / headline"
                   class="rounded-md bg-surface-2 border border-subtle px-2 py-1.5 text-sm">
            <input type="hidden" name="sort" value="{{ $sort }}">
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
            <a href="{{ route('onboarding.portal.finish', $portal->urlKey()) }}"
               class="rounded-md px-3 py-1.5 text-xs border border-subtle">
                Finish review →
            </a>
        </div>
    </form>

    {{-- Error debug modal --}}
    <div x-show="errorModal.open" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
         @click.self="errorModal.open = false"
         @keydown.escape.window="errorModal.open = false">
        <div class="w-full max-w-2xl max-h-[85vh] bg-surface rounded-md border border-subtle shadow-xl flex flex-col">
            <div class="flex items-center justify-between px-5 py-3 border-b border-subtle/40">
                <h3 class="text-base font-semibold">
                    <span x-text="errorModal.errors.length"></span>
                    listing(s) failed
                </h3>
                <button type="button" @click="errorModal.open = false"
                        class="text-muted hover:text-inherit text-xl leading-none">&times;</button>
            </div>
            <div class="flex-1 overflow-y-auto p-5 space-y-3">
                <template x-for="err in errorModal.errors" :key="err.rowId + '-' + err.at">
                    <div class="rounded-md bg-surface-2 border border-red-500/30 p-3 text-xs">
                        <div class="flex items-center justify-between mb-1">
                            <div class="font-semibold">
                                <span x-text="err.externalId || ('Row #' + err.rowId)"></span>
                                <span class="ml-2 text-muted font-normal text-[11px]" x-text="'(sent row_id=' + err.rowId + ')'"></span>
                            </div>
                            <div class="text-muted" x-text="'HTTP ' + (err.httpStatus ?? '—')"></div>
                        </div>
                        <div x-show="err.address" class="text-muted" x-text="err.address"></div>
                        <div class="mt-2 whitespace-pre-wrap font-mono text-red-500" x-text="err.message"></div>
                        <template x-if="err.serverErrors && err.serverErrors.length">
                            <ul class="mt-2 list-disc list-inside text-red-500">
                                <template x-for="(m, i) in err.serverErrors" :key="i">
                                    <li x-text="m"></li>
                                </template>
                            </ul>
                        </template>
                        <template x-if="err.diagnostics">
                            <details class="mt-2">
                                <summary class="cursor-pointer text-muted text-[11px] underline">diagnostics</summary>
                                <pre class="mt-1 text-[10px] leading-tight whitespace-pre-wrap" x-text="JSON.stringify(err.diagnostics, null, 2)"></pre>
                            </details>
                        </template>
                        <template x-if="err.raw && !err.diagnostics && !err.serverErrors?.length">
                            <details class="mt-2">
                                <summary class="cursor-pointer text-muted text-[11px] underline">raw server response</summary>
                                <pre class="mt-1 text-[10px] leading-tight whitespace-pre-wrap" x-text="JSON.stringify(err.raw, null, 2)"></pre>
                            </details>
                        </template>
                    </div>
                </template>
                <div x-show="!errorModal.errors.length" class="text-muted text-sm">No errors.</div>
            </div>
            <div class="px-5 py-3 border-t border-subtle/40 flex justify-between">
                <button type="button" @click="errorModal.errors = []"
                        class="rounded-md px-3 py-1.5 text-xs bg-surface-2 border border-subtle">
                    Clear log
                </button>
                <button type="button" @click="errorModal.open = false"
                        class="portal-cta rounded-md px-3 py-1.5 text-xs font-semibold">
                    Close
                </button>
            </div>
        </div>
    </div>

    {{-- Progress bar --}}
    <div x-show="progress.active" x-cloak class="rounded-md bg-surface p-3 border border-subtle/30">
        <div class="flex items-center justify-between text-xs mb-2">
            <span x-text="progress.label + ' — ' + progress.done + ' of ' + progress.total"></span>
            <span x-text="progress.total ? Math.round((progress.done / progress.total) * 100) + '%' : '0%'"></span>
        </div>
        <div class="w-full bg-surface-2 rounded-md h-2 overflow-hidden">
            <div class="h-full transition-all duration-200"
                 :style="'width: ' + (progress.total ? (progress.done / progress.total) * 100 : 0) + '%; background: var(--brand-button);'"></div>
        </div>
        <div x-show="progress.errors > 0" class="mt-2 text-xs text-red-500 flex items-center gap-2">
            <span x-text="progress.errors + ' listing(s) failed'"></span>
            <button type="button" class="underline" @click="errorModal.open = true">view details</button>
        </div>
    </div>

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
            @php
                $baseSortParams = array_filter([
                    'status'       => $status !== 'pending' ? $status : null,
                    'listing_type' => $type !== 'all' ? $type : null,
                    'search'       => $search !== '' ? $search : null,
                    'per_page'     => (int) $perPage !== 30 ? (int) $perPage : null,
                ]);
                $nextStatusSort = $sort === 'status_asc' ? 'status_desc' : 'status_asc';
                $statusSortUrl  = route('onboarding.portal.review', $portal->urlKey()) . '?' . http_build_query(array_merge($baseSortParams, ['sort' => $nextStatusSort]));
                $sortArrow = $sort === 'status_asc' ? '↑' : ($sort === 'status_desc' ? '↓' : '↕');
            @endphp
            <thead class="text-xs uppercase text-muted border-b border-subtle">
                <tr>
                    <th class="px-2 py-2"><input type="checkbox" @change="toggleAll($event)"></th>
                    <th class="px-2 py-2 text-left">Photo</th>
                    <th class="px-2 py-2 text-left">Listing #</th>
                    <th class="px-2 py-2 text-left">Headline</th>
                    <th class="px-2 py-2 text-left">Address</th>
                    <th class="px-2 py-2 text-left">Type</th>
                    <th class="px-2 py-2 text-left">
                        <a href="{{ $statusSortUrl }}" class="hover:text-primary">
                            Listing Status <span class="text-[10px] opacity-60">{{ $sortArrow }}</span>
                        </a>
                    </th>
                    <th class="px-2 py-2 text-left">Price</th>
                    <th class="px-2 py-2 text-left">Beds/Baths</th>
                    <th class="px-2 py-2 text-left">Agent</th>
                    <th class="px-2 py-2 text-left">Photos</th>
                    <th class="px-2 py-2 text-left">Review</th>
                    <th class="px-2 py-2 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                @php
                    $m = $row->mapped_json ?? [];
                    $errs = (array) ($row->errors_json ?? []);
                    $isProcessing = $row->isProcessing();
                    $imgs = (array) $row->image_urls_json;
                    $firstImg = $imgs[0] ?? null;
                @endphp
                <tr class="border-b border-subtle/40"
                    :class="{'opacity-60': rowState[{{ $row->id }}]?.busy, 'hidden': rowState[{{ $row->id }}]?.hidden}"
                    data-row="{{ $row->id }}">
                    <td class="px-2 py-2">
                        <input x-show="canSelect({{ $row->id }}, '{{ $row->status }}', {{ $isProcessing ? 'true' : 'false' }})"
                               type="checkbox" value="{{ $row->id }}" @change="toggleRow({{ $row->id }}, $event)" :checked="selected.includes({{ $row->id }})">
                    </td>
                    <td class="px-2 py-2">
                        @if ($firstImg)
                            <img src="{{ $firstImg }}" alt="" loading="lazy"
                                 class="w-16 h-12 object-cover rounded-md border border-subtle/40 bg-surface-2"
                                 onerror="this.style.display='none'">
                        @else
                            <div class="w-16 h-12 rounded-md border border-subtle/40 bg-surface-2 flex items-center justify-center text-muted text-[10px]">no image</div>
                        @endif
                    </td>
                    <td class="px-2 py-2 font-mono text-xs">{{ $row->external_id }}</td>
                    <td class="px-2 py-2 max-w-[260px]">
                        @php $headline = $m['headline'] ?? $m['title'] ?? null; @endphp
                        @if ($headline)
                            <span class="block truncate" title="{{ $headline }}">{{ $headline }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="px-2 py-2">{{ $m['address'] ?? '—' }}</td>
                    <td class="px-2 py-2">{{ $m['listing_type'] ?? '' }}</td>
                    <td class="px-2 py-2 text-xs">
                        @php $listingStatus = $m['status'] ?? null; @endphp
                        @if ($listingStatus)
                            <span class="px-2 py-0.5 rounded-md bg-surface-2 border border-subtle/40">{{ $listingStatus }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
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
                    <td class="px-2 py-2 text-xs">{{ count($imgs) }}</td>
                    <td class="px-2 py-2">
                        <template x-if="rowState[{{ $row->id }}]?.status">
                            <span class="px-2 py-0.5 rounded-md text-xs"
                                  :class="{
                                      'bg-amber-500/20 text-amber-700': rowState[{{ $row->id }}].status === 'processing',
                                      'bg-emerald-500/20 text-emerald-700': rowState[{{ $row->id }}].status === 'confirmed',
                                      'bg-red-500/20 text-red-700': rowState[{{ $row->id }}].status === 'error',
                                      'bg-surface-2': ['excluded'].includes(rowState[{{ $row->id }}].status),
                                  }"
                                  x-text="rowState[{{ $row->id }}].status === 'processing' ? 'processing…' : rowState[{{ $row->id }}].status"></span>
                        </template>
                        <template x-if="!rowState[{{ $row->id }}]?.status">
                            @if ($isProcessing)
                                <span class="px-2 py-0.5 rounded-md text-xs bg-amber-500/20 text-amber-700">processing…</span>
                            @elseif (!empty($errs))
                                <span class="px-2 py-0.5 rounded-md text-xs bg-red-500/20 text-red-700" title="{{ implode('; ', $errs) }}">error</span>
                            @else
                                <span class="px-2 py-0.5 rounded-md text-xs bg-surface-2">{{ $row->status }}</span>
                            @endif
                        </template>
                    </td>
                    <td class="px-2 py-2 text-right whitespace-nowrap">
                        <span x-show="canAct({{ $row->id }}, '{{ $row->status }}', {{ $isProcessing ? 'true' : 'false' }})">
                            <button type="button" @click.stop="confirmRow({{ $row->id }})"
                                    class="portal-accent text-xs mr-2 font-semibold"
                                    :disabled="rowState[{{ $row->id }}]?.busy === true">Confirm</button>
                            <button type="button" @click.stop="excludeRow({{ $row->id }})"
                                    class="text-xs text-red-500"
                                    :disabled="rowState[{{ $row->id }}]?.busy === true">Exclude</button>
                        </span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="13" class="py-10 text-center text-muted">No listings match your filters.</td></tr>
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
        rowState: {},
        counts: @json($counts),
        progress: { active: false, total: 0, done: 0, errors: 0, label: '' },
        errorModal: { open: false, errors: [] },
        csrf: document.querySelector('meta[name=csrf-token]')?.content ?? '',

        recordError(id, r) {
            const tr = document.querySelector('tr[data-row="' + id + '"]');
            const externalId = tr?.querySelector('td.font-mono')?.innerText?.trim() ?? '';
            const addressCell = tr?.querySelectorAll('td')[4];
            const address = addressCell?.innerText?.trim() ?? '';
            this.errorModal.errors.push({
                rowId: id,
                externalId,
                address,
                httpStatus: r?.status ?? null,
                message: r?.data?.message || r?.error || ('HTTP ' + (r?.status ?? '—')),
                serverErrors: (r?.data?.errors && Array.isArray(r.data.errors)) ? r.data.errors
                              : (r?.data?.errors ? Object.values(r.data.errors).flat() : []),
                diagnostics: r?.data?.diagnostics ?? null,
                raw: r?.data ?? null,
                at: Date.now(),
            });
            this.errorModal.open = true;
        },

        canSelect(id, status, isProcessing) {
            const st = this.rowState[id];
            if (st?.hidden) return false;
            const current = st?.status ?? status;
            return (current === 'pending' || current === 'error') && !(st?.busy) && !isProcessing;
        },
        canAct(id, status, isProcessing) {
            const st = this.rowState[id];
            if (st?.hidden) return false;
            const current = st?.status ?? status;
            return (current === 'pending' || current === 'error') && !isProcessing;
        },
        toggleRow(id, e) {
            if (e.target.checked) {
                if (!this.selected.includes(id)) this.selected.push(id);
            } else {
                this.selected = this.selected.filter(x => x !== id);
            }
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
            let data = null;
            try { data = await res.json(); } catch (e) {}
            return { ok: res.ok, status: res.status, data };
        },
        setRow(id, patch) {
            this.rowState = { ...this.rowState, [id]: { ...(this.rowState[id] ?? {}), ...patch } };
        },
        async confirmSingle(id) {
            this.setRow(id, { busy: true, status: 'processing' });
            let r;
            try {
                r = await this.post(`/onboarding/${token}/rows/${id}/confirm`);
            } catch (e) {
                r = { ok: false, status: 0, data: null, error: e?.message ?? 'Network error' };
            }
            if (r.ok && r.data?.status === 'confirmed') {
                this.setRow(id, { busy: false, status: 'confirmed' });
                if (r.data.counts) this.counts = r.data.counts;
                this.selected = this.selected.filter(x => x !== id);
                return { ok: true };
            }
            const serverErrors = Array.isArray(r.data?.errors) ? r.data.errors
                                : (r.data?.errors ? Object.values(r.data.errors).flat() : []);
            const msg = serverErrors.join('; ') || r.data?.message || r.error || ('HTTP ' + r.status);
            this.setRow(id, { busy: false, status: 'error', error: msg });
            this.recordError(id, r);
            return { ok: false, error: msg };
        },
        // A SINGLE review action (confirm or exclude) removes the row and then
        // reloads the list at the front of the remaining queue, so freshly
        // surfaced listings flow in without a manual refresh. Bulk actions skip
        // the per-item reload — they refresh once at the end via finishBatch().
        async confirmRow(id) {
            const r = await this.confirmSingle(id);
            if (r.ok) this.afterSingleAction(id);
        },
        async excludeRow(id) {
            if (!confirm('Exclude this listing from going live?')) return;
            const ok = await this.doExclude(id);
            if (ok) this.afterSingleAction(id);
        },
        // Low-level exclude with no confirm dialog — shared by single + bulk.
        async doExclude(id) {
            this.setRow(id, { busy: true });
            let r;
            try {
                r = await this.post(`/onboarding/${token}/rows/${id}/exclude`);
            } catch (e) {
                r = { ok: false, status: 0, data: null, error: e?.message ?? 'Network error' };
            }
            if (r.ok) {
                this.setRow(id, { busy: false, status: 'excluded', hidden: true });
                this.selected = this.selected.filter(x => x !== id);
                return true;
            }
            this.setRow(id, { busy: false, status: 'error' });
            this.recordError(id, r);
            return false;
        },
        afterSingleAction(id) {
            this.setRow(id, { hidden: true });
            this.reloadFresh(300);
        },
        // Reload the list at the front of the remaining queue (drops ?page=)
        // while keeping the current filters and page size.
        reloadFresh(delay = 0) {
            const go = () => {
                const url = new URL(window.location.href);
                url.searchParams.delete('page');
                window.location.assign(url.toString());
            };
            delay ? setTimeout(go, delay) : go();
        },
        async reassignAgent(id, userId) {
            if (!userId) return;
            const r = await this.post(`/onboarding/${token}/rows/${id}/reassign`, {user_id: parseInt(userId)});
            if (!r.ok) alert('Could not reassign agent.');
        },
        async runBatch(ids, label) {
            if (!ids.length) return;
            this.progress = { active: true, total: ids.length, done: 0, errors: 0, label };
            for (const id of ids) {
                const r = await this.confirmSingle(id);
                this.progress.done += 1;
                if (!r.ok) this.progress.errors += 1;
            }
            this.finishBatch();
        },
        // Exclude many ids under one shared progress bar (no per-row dialog).
        async excludeBatch(ids, label) {
            if (!ids.length) return;
            this.progress = { active: true, total: ids.length, done: 0, errors: 0, label };
            for (const id of ids) {
                const ok = await this.doExclude(id);
                this.progress.done += 1;
                if (!ok) this.progress.errors += 1;
            }
            this.finishBatch();
        },
        // Called once a whole batch is done — wait for every item, THEN refresh
        // the list. If some failed, keep them on screen (and the error log open)
        // instead of reloading, so they can be inspected and retried.
        finishBatch() {
            if (this.progress.errors > 0) {
                setTimeout(() => { this.progress = { active: false, total: 0, done: 0, errors: 0, label: '' }; }, 1500);
                this.refreshCounts();
            } else {
                this.reloadFresh(900);
            }
        },
        async bulkConfirm() {
            if (!this.selected.length) {
                alert('Select at least one listing first.');
                return;
            }
            if (!confirm(`Confirm ${this.selected.length} listings?`)) return;
            const ids = [...this.selected];
            await this.runBatch(ids, 'Confirming selected');
        },
        async bulkExclude() {
            if (!this.selected.length) {
                alert('Select at least one listing first.');
                return;
            }
            if (!confirm(`Exclude ${this.selected.length} listings?`)) return;
            await this.excludeBatch([...this.selected], 'Excluding selected');
        },
        async confirmAllPending() {
            const boxes = document.querySelectorAll('tbody input[type=checkbox]');
            const ids = [];
            boxes.forEach(b => { const v = parseInt(b.value); if (v) ids.push(v); });
            if (!ids.length) { alert('No pending listings on this page.'); return; }
            if (!confirm(`Confirm ${ids.length} pending listings on this page?`)) return;
            await this.runBatch(ids, 'Confirming all pending');
        },
        async refreshCounts() {
            try {
                const res = await fetch(`/onboarding/${token}/status`, {headers:{Accept:'application/json'}});
                const data = await res.json();
                if (data?.counts) this.counts = data.counts;
            } catch (e) {}
        },
    };
}
</script>
@endsection
