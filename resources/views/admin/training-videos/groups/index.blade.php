{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Training Video Groups list. Owner-only.
    Spec: .ai/specs/training-videos.md §4.2
--}}
@extends('layouts.corex')

@section('title', 'Training Video Groups')

@section('corex-content')
@php
    $sortLink = function (string $key, string $label) use ($sort, $dir) {
        $nextDir = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
        $arrow   = $sort === $key ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
        $url     = request()->fullUrlWithQuery(['sort' => $key, 'dir' => $nextDir, 'page' => null]);
        return '<a href="' . e($url) . '" class="hover:underline">' . e($label) . $arrow . '</a>';
    };
    $th = 'text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider';
    $field = 'rounded-md px-3 py-2 text-sm';
    $fieldStyle = 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);';
@endphp
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Training Video Groups</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    Each group is a section on the public Training Videos page, in A–Z order. Archiving a group takes it and its videos off the page.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.training-videos.index') }}" class="corex-btn-outline corex-btn-on-brand text-xs">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/>
                    </svg>
                    Back to Training Videos
                </a>
                <a href="{{ route('admin.training-videos.groups.create') }}" class="corex-btn-primary text-xs">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                    New group
                </a>
            </div>
        </div>
    </div>

    @if (session('status'))
        <div role="status" class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-emerald) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-emerald) 30%, transparent);
                    color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            {{ $errors->first() }}
        </div>
    @endif

    @if (! $hasAny)
        <div class="rounded-md px-6 py-12 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <p class="text-sm font-medium" style="color: var(--text-primary);">No groups yet</p>
            <p class="mt-1 text-xs max-w-md mx-auto" style="color: var(--text-muted);">
                Groups split the public page into sections, like "Getting started" or "Listings". Videos without a group still show, under "More videos".
            </p>
            <a href="{{ route('admin.training-videos.groups.create') }}" class="corex-btn-primary text-xs mt-4 inline-flex">
                Create a group
            </a>
        </div>
    @else
        <form method="GET" action="{{ route('admin.training-videos.groups.index') }}"
              class="rounded-md p-4 flex flex-wrap items-end gap-3" style="background: var(--surface); border: 1px solid var(--border);">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="dir" value="{{ $dir }}">
            <div class="flex-1 min-w-[200px]">
                <label for="q" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
                <input id="q" name="q" type="search" value="{{ $q }}" placeholder="Group name"
                       class="w-full {{ $field }}" style="{{ $fieldStyle }}">
            </div>
            <div>
                <label for="status" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
                <select id="status" name="status" class="{{ $field }}" style="{{ $fieldStyle }}">
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="archived" @selected($status === 'archived')>Archived</option>
                    <option value="all" @selected($status === 'all')>All</option>
                </select>
            </div>
            <div>
                <label for="from" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Added from</label>
                <input id="from" name="from" type="date" value="{{ request('from') }}" class="{{ $field }}" style="{{ $fieldStyle }}">
            </div>
            <div>
                <label for="to" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Added to</label>
                <input id="to" name="to" type="date" value="{{ request('to') }}" class="{{ $field }}" style="{{ $fieldStyle }}">
            </div>
            <div class="flex items-center gap-2">
                <button type="submit" class="corex-btn-primary text-xs">Apply</button>
                @if ($filtered)
                    <a href="{{ route('admin.training-videos.groups.index') }}" class="corex-btn-outline text-xs">Clear</a>
                @endif
            </div>
        </form>

        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            @if ($groups->isEmpty())
                <div class="px-6 py-12 text-center">
                    <p class="text-sm font-medium" style="color: var(--text-primary);">No groups match these filters</p>
                    <a href="{{ route('admin.training-videos.groups.index') }}" class="text-xs mt-2 inline-block hover:underline" style="color: var(--corex-accent);">Clear filters</a>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm ds-table">
                        <thead>
                            <tr style="background: var(--surface-2); color: var(--text-muted);">
                                <th class="{{ $th }}">{!! $sortLink('name', 'Group') !!}</th>
                                <th class="{{ $th }}">{!! $sortLink('videos', 'Videos') !!}</th>
                                <th class="{{ $th }}">{!! $sortLink('created', 'Added') !!}</th>
                                <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wider"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($groups as $g)
                                <tr style="border-bottom: 1px solid var(--border);">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.training-videos.groups.edit', $g) }}" class="font-medium hover:underline" style="color: var(--corex-accent);">{{ $g->name }}</a>
                                        @if ($g->trashed())
                                            <span class="ds-badge ml-1" style="--ds-badge-c: var(--text-muted);">Archived</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.training-videos.index', ['group' => $g->id]) }}" class="hover:underline" style="color: var(--text-primary);">
                                            {{ number_format($g->videos_count) }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap" style="color: var(--text-primary);">{{ $g->created_at?->format('j M Y') }}</td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        <a href="{{ route('admin.training-videos.groups.edit', $g) }}" class="corex-btn-outline text-xs">Rename</a>
                                        @if ($g->trashed())
                                            <form method="POST" action="{{ route('admin.training-videos.groups.restore', $g) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('admin.training-videos.groups.destroy', $g) }}" class="inline"
                                                  onsubmit="return confirm('Archive this group? It and its {{ $g->videos_count }} video(s) come off the public page. Restoring the group brings them back.');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="corex-btn-outline text-xs">Archive</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @if ($groups->hasPages())
            <div>{{ $groups->links() }}</div>
        @endif
    @endif

</div>
@endsection
