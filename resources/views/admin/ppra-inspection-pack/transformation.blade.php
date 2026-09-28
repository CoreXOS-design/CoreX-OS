{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5" x-data="ppraTransformationForm(@json($current?->entry_type === 'structured' ? ($current->structured_data ?? []) : []))">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Transformation Initiatives</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    Item (i) — write the agency's B-BBEE / transformation initiatives in CoreX, or upload your own statement.
                </p>
            </div>
            <a href="{{ route('admin.ppra-inspection-pack.index') }}" class="corex-btn-outline text-xs">Back to checklist</a>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background:color-mix(in srgb, #15803d 10%, transparent); color:#15803d; border:1px solid color-mix(in srgb, #15803d 25%, transparent);">
            {{ session('success') }}
        </div>
    @endif

    @if($current)
        <div class="rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
            <div class="text-xs font-semibold mb-1" style="color:var(--text-muted);">Current version</div>
            <div class="text-sm" style="color:var(--text-primary);">{{ $current->summary }}</div>
            <div class="text-xs mt-1" style="color:var(--text-muted);">
                {{ ucfirst($current->entry_type) }} &bull; saved {{ $current->created_at->format('d M Y H:i') }} by {{ $current->createdBy?->name ?? '—' }}
                @if($current->entry_type === 'document')
                    &bull; <a href="{{ route('admin.ppra-inspection-pack.transformation.download', $current) }}" style="color:var(--brand-icon,#0ea5e9); font-weight:600;">Download</a>
                @endif
            </div>
        </div>
    @endif

    {{-- Structured form --}}
    <div class="rounded-md p-4 space-y-3" style="background:var(--surface); border:1px solid var(--border);">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-bold" style="color:var(--text-primary);">Write it in CoreX</h2>
            <button type="button" @click="askEllie()" :disabled="drafting" class="corex-btn-outline text-xs">
                <span x-show="!drafting">Ask Ellie to help me draft</span>
                <span x-show="drafting">Drafting…</span>
            </button>
        </div>
        <p x-show="draftError" x-text="draftError" class="text-xs" style="color:var(--ds-crimson,#c41e3a);"></p>

        <form method="POST" action="{{ route('admin.ppra-inspection-pack.transformation.store-structured') }}" class="space-y-3">
            @csrf
            <template x-for="(row, idx) in initiatives" :key="idx">
                <div class="rounded-md p-3 space-y-2" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div class="flex items-start justify-between gap-2">
                        <textarea :name="'initiatives['+idx+'][description]'" x-model="row.description" required
                                  placeholder="Describe the initiative (what, and why it counts as transformation)"
                                  rows="2" class="w-full text-sm rounded-md px-2 py-1.5" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                        <button type="button" @click="initiatives.splice(idx, 1)" class="text-xs font-semibold shrink-0" style="color:var(--ds-crimson,#c41e3a);">Remove</button>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Start date</label>
                            <input type="date" :name="'initiatives['+idx+'][start_date]'" x-model="row.start_date" class="w-full text-xs rounded-md px-2 py-1" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">End date</label>
                            <input type="date" :name="'initiatives['+idx+'][end_date]'" x-model="row.end_date" class="w-full text-xs rounded-md px-2 py-1" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">People involved</label>
                            <input type="text" :name="'initiatives['+idx+'][people_involved]'" x-model="row.people_involved" placeholder="e.g. 3 candidate practitioners" class="w-full text-xs rounded-md px-2 py-1" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Spend (ZAR)</label>
                            <input type="number" step="0.01" min="0" :name="'initiatives['+idx+'][spend_amount]'" x-model="row.spend_amount" class="w-full text-xs rounded-md px-2 py-1" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                    </div>
                </div>
            </template>

            <button type="button" @click="initiatives.push({description:'', start_date:'', end_date:'', people_involved:'', spend_amount:''})" class="corex-btn-outline text-xs">
                + Add initiative
            </button>

            <div>
                <button type="submit" class="corex-btn-primary text-xs" :disabled="initiatives.length === 0">Save statement</button>
            </div>
        </form>
    </div>

    {{-- Document upload alternative --}}
    <div class="rounded-md p-4 space-y-3" style="background:var(--surface); border:1px solid var(--border);">
        <h2 class="text-sm font-bold" style="color:var(--text-primary);">Or upload your own statement</h2>
        <form method="POST" action="{{ route('admin.ppra-inspection-pack.transformation.store-document') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Document (PDF/DOC/DOCX)</label>
                <input type="file" name="document" required accept=".pdf,.doc,.docx" class="text-xs" style="color:var(--text-primary);">
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Caption (optional)</label>
                <input type="text" name="caption" maxlength="255" class="text-xs rounded-md px-2 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Upload</button>
        </form>
    </div>

    {{-- Version history — full CRUD-list floor --}}
    <div class="rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
        <h2 class="text-sm font-bold mb-3" style="color:var(--text-primary);">Version History</h2>

        <form method="GET" class="flex flex-wrap items-end gap-3 mb-3">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Summary text"
                       class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Type</label>
                <select name="entry_type" class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    <option value="">All</option>
                    <option value="structured" @selected(request('entry_type')==='structured')>Structured</option>
                    <option value="document" @selected(request('entry_type')==='document')>Document</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">From</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}" class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">To</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}" class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            </div>
            <button type="submit" class="corex-btn-primary text-xs">Filter</button>
        </form>

        @if($versions->isEmpty())
            <div class="py-8 text-center">
                <h3 class="text-sm font-semibold mb-1" style="color:var(--text-primary);">
                    {{ request()->hasAny(['search','entry_type','date_from','date_to']) ? 'No versions match this filter' : 'No transformation initiatives statement has been written yet' }}
                </h3>
            </div>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                        <th class="text-left px-3 py-2 text-xs font-semibold" style="color:var(--text-muted);">Summary</th>
                        <th class="text-left px-3 py-2 text-xs font-semibold" style="color:var(--text-muted);">Type</th>
                        <th class="text-left px-3 py-2 text-xs font-semibold" style="color:var(--text-muted);">Saved</th>
                        <th class="text-left px-3 py-2 text-xs font-semibold" style="color:var(--text-muted);">By</th>
                        <th class="text-left px-3 py-2 text-xs font-semibold" style="color:var(--text-muted);">Status</th>
                        <th class="text-right px-3 py-2 text-xs font-semibold" style="color:var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($versions as $version)
                    <tr style="border-bottom:1px solid var(--border);">
                        <td class="px-3 py-2" style="color:var(--text-primary);">{{ $version->summary }}</td>
                        <td class="px-3 py-2" style="color:var(--text-secondary);">{{ ucfirst($version->entry_type) }}</td>
                        <td class="px-3 py-2" style="color:var(--text-secondary);">{{ $version->created_at->format('d M Y H:i') }}</td>
                        <td class="px-3 py-2" style="color:var(--text-secondary);">{{ $version->createdBy?->name ?? '—' }}</td>
                        <td class="px-3 py-2">
                            @if(!$version->trashed())
                                <span class="text-xs font-semibold px-2 py-0.5 rounded-full" style="background:color-mix(in srgb, #15803d 12%, transparent); color:#15803d;">Current</span>
                            @else
                                <span class="text-xs" style="color:var(--text-muted);">Archived</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right">
                            @if($version->entry_type === 'document')
                                <a href="{{ route('admin.ppra-inspection-pack.transformation.download', $version->id) }}" class="text-xs font-semibold" style="color:var(--brand-icon,#0ea5e9);">Download</a>
                            @endif
                            @if(!$version->trashed())
                                <form method="POST" action="{{ route('admin.ppra-inspection-pack.transformation.destroy', $version) }}" class="inline ml-2" onsubmit="return confirm('Archive this version?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs font-semibold" style="color:var(--ds-crimson,#c41e3a);">Archive</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('admin.ppra-inspection-pack.transformation.restore', $version->id) }}" class="inline ml-2">
                                    @csrf
                                    <button type="submit" class="text-xs font-semibold" style="color:var(--brand-icon,#0ea5e9);">Restore</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="pt-3">{{ $versions->links() }}</div>
        @endif
    </div>

</div>

<script>
function ppraTransformationForm(existing) {
    return {
        initiatives: Array.isArray(existing) && existing.length ? existing : [{description:'', start_date:'', end_date:'', people_involved:'', spend_amount:''}],
        drafting: false,
        draftError: null,
        askEllie() {
            this.drafting = true;
            this.draftError = null;
            fetch('{{ route('admin.ppra-inspection-pack.transformation.draft') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}',
                    'Accept': 'application/json',
                },
            })
                .then(r => r.json())
                .then(data => {
                    this.drafting = false;
                    if (!data.ok || !data.draft) {
                        this.draftError = 'Ellie could not draft a suggestion right now — try again shortly.';
                        return;
                    }
                    this.initiatives.push({description: data.draft, start_date: '', end_date: '', people_involved: '', spend_amount: ''});
                })
                .catch(() => {
                    this.drafting = false;
                    this.draftError = 'Ellie could not draft a suggestion right now — try again shortly.';
                });
        },
    };
}
</script>
@endsection
