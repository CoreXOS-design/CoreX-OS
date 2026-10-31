{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner" data-tour="cop-pir-intro">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Remediation Log</h1>
                <p class="text-xs" style="color: var(--text-muted);">Every remediation note ever set against a PPRA Inspection Pack gap.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
                <a href="{{ route('admin.ppra-inspection-pack.index') }}" class="corex-btn-outline text-xs">Back to checklist</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background:color-mix(in srgb, #15803d 10%, transparent); color:#15803d; border:1px solid color-mix(in srgb, #15803d 25%, transparent);">
            {{ session('success') }}
        </div>
    @endif

    <form method="GET" class="props-filter-panel flex flex-wrap items-end gap-3 rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Search</label>
            <input data-tour="cop-pir-search" class="list-header-filter {{ (request('search') !== null && request('search') !== '') ? 'is-active' : '' }}" type="text" name="search" value="{{ request('search') }}" placeholder="Item or note text"
                  >
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Status</label>
            <select data-tour="cop-pir-status" class="list-header-filter {{ (request('status') !== null && request('status') !== '') ? 'is-active' : '' }}" name="status">
                <option value="">All statuses</option>
                <option value="open" @selected(request('status')==='open')>Open</option>
                <option value="resolved" @selected(request('status')==='resolved')>Resolved</option>
                <option value="overdue" @selected(request('status')==='overdue')>Overdue</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Sort</label>
            <select data-tour="cop-pir-sort" class="list-header-filter {{ (false) ? 'is-active' : '' }}" name="sort">
                <option value="due_date" @selected(request('sort', 'due_date')==='due_date')>Due date</option>
                <option value="created" @selected(request('sort')==='created')>Created date</option>
            </select>
        </div>
        <button type="submit" class="corex-btn-primary text-xs" style="height:2.25rem;" data-tour="cop-pir-filter-btn">Filter</button>
    </form>

    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);" data-tour="cop-pir-table">
        @if($notes->isEmpty())
            <div class="py-12 px-6 text-center">
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">
                    {{ request()->hasAny(['search','status']) ? 'No remediation notes match this filter' : 'No remediation notes yet' }}
                </h3>
                <p class="text-sm" style="color:var(--text-muted);">
                    {{ request()->hasAny(['search','status']) ? 'Try a different search or status.' : 'Set a remediation date on an open checklist item to start tracking it here.' }}
                </p>
            </div>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wide" style="color:var(--text-muted);">Item</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wide" style="color:var(--text-muted);">Due Date</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wide" style="color:var(--text-muted);">Note</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wide" style="color:var(--text-muted);">Assigned To</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wide" style="color:var(--text-muted);">Status</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wide" style="color:var(--text-muted);">Created By</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wide" style="color:var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($notes as $note)
                    @php
                        $overdue = !$note->resolved_at && $note->remediation_due_date && $note->remediation_due_date->isPast();
                    @endphp
                    <tr style="border-bottom:1px solid var(--border);">
                        <td class="px-4 py-3" style="color:var(--text-primary);">{{ strtoupper($note->checklist_item_slug) }}</td>
                        <td class="px-4 py-3" style="color: {{ $overdue ? 'var(--ds-crimson,#c41e3a)' : 'var(--text-secondary)' }};">
                            {{ $note->remediation_due_date?->format('d M Y') ?? '—' }}
                            @if($overdue) <span class="text-xs font-semibold">(overdue)</span> @endif
                        </td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $note->note ?? '—' }}</td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $note->assignedTo?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @if($note->resolved_at)
                                <span class="ds-badge ds-badge-success">Resolved {{ $note->resolved_at->format('d M Y') }}</span>
                            @else
                                <span class="ds-badge ds-badge-warning">Open</span>
                            @endif
                        </td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $note->createdBy?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            @if(!$note->resolved_at)
                                <form method="POST" action="{{ route('admin.ppra-inspection-pack.gap-notes.resolve', $note) }}" class="inline">
                                    @csrf
                                    <button type="submit" data-tour="cop-pir-resolve" class="text-xs font-semibold uppercase tracking-wide" style="color:#15803d;">Mark resolved</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('admin.ppra-inspection-pack.gap-notes.destroy', $note) }}" class="inline ml-2"
                                  onsubmit="return confirm('Archive this remediation note?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs font-semibold uppercase tracking-wide" style="color:var(--ds-crimson,#c41e3a);">Archive</button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="px-4 py-3" style="border-top:1px solid var(--border);">
                {{ $notes->links() }}
            </div>
        @endif
    </div>

</div>
@endsection
