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

@section('corex-content')
<div class="w-full space-y-5">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Rental Lease Agreements</h1>
                <p class="text-xs" style="color: var(--text-muted);">Link your agency's own lease agreement here. Until one is linked and ready, "Create lease &amp; prepare for signing" stays unavailable.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('corex.rental-lease-templates.create') }}" class="corex-btn-primary text-xs">New Template</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif

    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
    <form method="GET" class="flex flex-wrap items-end gap-3">
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search by name…" class="list-header-filter text-sm {{ filled($filters['q'] ?? '') ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Category</label>
            <select name="category" class="list-header-filter text-sm {{ filled($filters['category'] ?? null) ? 'is-active' : '' }}">
                <option value="">All categories</option>
                @foreach($categories as $c)
                    <option value="{{ $c }}" @selected(($filters['category'] ?? '') === $c)>{{ ucfirst(str_replace('_', ' ', $c)) }}</option>
                @endforeach
            </select>
        </div>
        <label class="flex items-center gap-2 text-xs" style="height:2.25rem; color: var(--text-secondary);">
            <input type="checkbox" name="archived" value="1" @checked($showArchived)> Show archived
        </label>
        <button type="submit" class="corex-btn-primary text-sm" style="height:2.25rem;">Filter</button>
        <div class="flex-1"></div>
        <span class="ds-badge ds-badge-pill ds-badge-info">
            {{ number_format($templates->total()) }} {{ \Illuminate\Support\Str::plural('template', $templates->total()) }}
        </span>
    </form>
    </div>

    @if($templates->isEmpty())
        <div class="text-sm px-6 py-12 text-center rounded-md" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);">
            @if($hasAny)
                No templates match this filter.
            @else
                Nothing set up yet — add your agency's lease/renewal template to get started.
            @endif
        </div>
    @else
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr style="background: var(--surface-2); color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('name') }}">Name{{ $sortIndicator('name') }}</a></th>
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('category') }}">Category{{ $sortIndicator('category') }}</a></th>
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Source document</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('is_active') }}">Active{{ $sortIndicator('is_active') }}</a></th>
                        <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Lease signing</th>
                        <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($templates as $t)
                        <tr style="border-top: 1px solid var(--border); color: var(--text-secondary);">
                            <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $t->name }}@if($t->is_default) <span class="ds-badge ds-badge-pill ds-badge-info">Default</span>@endif</td>
                            <td class="px-4 py-3">{{ ucfirst(str_replace('_', ' ', $t->category)) }}</td>
                            <td class="px-4 py-3">{{ $t->template?->name ?? '(deleted)' }}</td>
                            <td class="px-4 py-3">
                                <span class="ds-badge {{ $t->is_active ? 'ds-badge-success' : 'ds-badge-muted' }}">{{ $t->is_active ? 'Active' : 'Inactive' }}</span>
                            </td>
                            <td class="px-4 py-3">
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
                            <td class="px-4 py-3 text-right space-x-2">
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
            @if($templates->hasPages())
            <div class="px-5 py-4" style="border-top:1px solid var(--border);">
                {{ $templates->links() }}
            </div>
            @endif
        </div>
    @endif
</div>
@endsection
