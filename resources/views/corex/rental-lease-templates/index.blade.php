@extends('layouts.corex')

{{--
    .ai/specs/rental-renewals.md §5(b)/§9 — GATE 1. List-screen floor
    (BUILD_STANDARD §1b): search (name), sort (name/category/active —
    default: name asc), filter (category, archived), pagination, real
    empty state.
--}}

@php
    $sortLink = fn ($col) => route('corex.rental-lease-templates.index', array_merge(
        request()->except('page'),
        ['sort' => $col, 'direction' => ($sort === $col && $direction === 'asc') ? 'desc' : 'asc']
    ));
    $sortIndicator = fn ($col) => $sort === $col ? ($direction === 'asc' ? ' ▲' : ' ▼') : '';
@endphp

@section('content')
<div class="p-6 space-y-4">
    @if(session('success'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">{{ session('success') }}</div>
    @endif

    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Rental Lease Agreements</h1>
        <a href="{{ route('corex.rental-lease-templates.create') }}" class="corex-btn-primary text-xs">New Template</a>
    </div>
    <p class="text-sm" style="color: var(--text-muted);">Link your agency's own lease agreement here. Until one is linked and ready, "Create lease &amp; prepare for signing" stays unavailable.</p>

    <form method="GET" class="flex flex-wrap gap-2 items-end">
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search by name…" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
        <select name="category" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
            <option value="">All categories</option>
            @foreach($categories as $c)
                <option value="{{ $c }}" @selected(($filters['category'] ?? '') === $c)>{{ ucfirst(str_replace('_', ' ', $c)) }}</option>
            @endforeach
        </select>
        <label class="flex items-center gap-1 text-xs">
            <input type="checkbox" name="archived" value="1" @checked($showArchived)> Show archived
        </label>
        <button type="submit" class="corex-btn-secondary text-xs">Filter</button>
    </form>

    @if($templates->isEmpty())
        <div class="text-sm p-6 text-center rounded-md" style="border: 1px solid var(--border); color: var(--text-muted);">
            @if($hasAny)
                No templates match this filter.
            @else
                Nothing set up yet — add your agency's lease/renewal template to get started.
            @endif
        </div>
    @else
        <div class="rounded-md overflow-hidden" style="border: 1px solid var(--border);">
            <table class="w-full text-sm">
                <thead style="background: var(--surface-2);">
                    <tr>
                        <th class="text-left px-3 py-2"><a href="{{ $sortLink('name') }}">Name{{ $sortIndicator('name') }}</a></th>
                        <th class="text-left px-3 py-2"><a href="{{ $sortLink('category') }}">Category{{ $sortIndicator('category') }}</a></th>
                        <th class="text-left px-3 py-2">Source document</th>
                        <th class="text-left px-3 py-2"><a href="{{ $sortLink('is_active') }}">Active{{ $sortIndicator('is_active') }}</a></th>
                        <th class="text-left px-3 py-2">Lease signing</th>
                        <th class="text-right px-3 py-2">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($templates as $t)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-3 py-2">{{ $t->name }}@if($t->is_default) <span class="ds-badge ds-badge-info">Default</span>@endif</td>
                            <td class="px-3 py-2">{{ ucfirst(str_replace('_', ' ', $t->category)) }}</td>
                            <td class="px-3 py-2">{{ $t->template?->name ?? '(deleted)' }}</td>
                            <td class="px-3 py-2">
                                <span class="ds-badge {{ $t->is_active ? 'ds-badge-success' : 'ds-badge-muted' }}">{{ $t->is_active ? 'Active' : 'Inactive' }}</span>
                            </td>
                            <td class="px-3 py-2">
                                @php $st = $statuses[$t->id] ?? null; @endphp
                                @if($st === null)
                                    <span class="text-xs" style="color: var(--text-muted);">—</span>
                                @elseif($st['state'] === 'ready')
                                    <span class="ds-badge ds-badge-success">Ready</span>
                                @elseif($st['state'] === 'needs_map')
                                    <span class="ds-badge ds-badge-warning">Needs field map</span>
                                @else
                                    <span class="ds-badge ds-badge-danger">Not usable: {{ $st['problems'][0] ?? '' }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right space-x-2">
                                @if($showArchived)
                                    <form method="POST" action="{{ route('corex.rental-lease-templates.restore', $t->id) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-xs">Restore</button>
                                    </form>
                                @else
                                    <a href="{{ route('corex.rental-lease-templates.edit', $t) }}" class="text-xs">Edit</a>
                                    @if($t->category === 'residential')
                                        <a href="{{ route('corex.rental-lease-templates.edit', $t) }}#field-map" class="text-xs">Map fields</a>
                                    @endif
                                    <form method="POST" action="{{ route('corex.rental-lease-templates.destroy', $t) }}" class="inline" data-confirm="Archive this template?" data-confirm-danger data-confirm-label="Archive">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs" style="color: var(--ds-crimson);">Archive</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div>{{ $templates->links() }}</div>
    @endif
</div>
@endsection
