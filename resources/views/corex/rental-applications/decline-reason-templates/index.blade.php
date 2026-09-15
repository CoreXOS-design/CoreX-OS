{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

{{--
    Decline reason templates, 2026-09-15 — Johan: "a decline that tells an
    applicant how to fix it is something no other CRM does." This screen is
    the reason+guidance LIBRARY only (full CRUD, list screen, per the
    standing design floor stated in the spec before this was written) — the
    decline modal's picker, the send step, and the email merge are cc5's
    own files, not this one. See .ai/specs/rental-applications.md, "Decline
    reason templates — agency-configurable reason + guidance library."
--}}

@section('corex-content')
<div class="w-full space-y-5" x-data="{ addOpen: false, editOpenId: null }">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Decline Reason Templates</h1>
                <p class="text-xs" style="color: var(--text-muted);">The reasons and guidance an authoriser can pick from when declining a rental application. General tips only — never a number, never a promise of approval.</p>
            </div>
            <button type="button" class="corex-btn-primary text-xs" @click="addOpen = !addOpen">+ Add a template</button>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: var(--ds-emerald-soft, #ecfdf5); color: var(--ds-emerald, #059669);">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson, #dc2626) 10%, transparent); color: var(--ds-crimson, #dc2626);">
            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    {{-- Create — expand-in-place, same "space goes to function" pattern as AT-410's "File as…" and the highlighter settings' own inline add row. --}}
    <div x-show="addOpen" x-cloak class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="POST" action="{{ route('corex.settings.rental-applications.decline-reason-templates.store') }}" class="space-y-3">
            @csrf
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Reason</label>
                <input type="text" name="reason" value="{{ old('reason') }}" maxlength="255" required placeholder="e.g. Affordability" class="rounded-md px-3 py-2 text-sm w-full" style="border: 1px solid var(--border);">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Guidance — general tips only, never a number, never a promise of approval</label>
                <textarea name="guidance" rows="4" required placeholder="e.g. General tips that help going forward: ..." class="rounded-md px-3 py-2 text-sm w-full" style="border: 1px solid var(--border);">{{ old('guidance') }}</textarea>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" class="corex-btn-outline text-xs" @click="addOpen = false">Cancel</button>
                <button type="submit" class="corex-btn-primary text-xs">Save template</button>
            </div>
        </form>
    </div>

    <form method="GET" action="{{ route('corex.settings.rental-applications.decline-reason-templates.index') }}" class="rounded-md p-4 flex flex-wrap items-end gap-3" style="background: var(--surface); border: 1px solid var(--border);">
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ $q }}" placeholder="Reason or guidance text" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border); min-width: 260px;">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
            <select name="status" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <option value="active" @selected($status === 'active')>Active</option>
                <option value="archived" @selected($status === 'archived')>Archived</option>
                <option value="all" @selected($status === 'all')>All</option>
            </select>
        </div>
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="direction" value="{{ $direction }}">
        <button type="submit" class="corex-btn-primary text-xs">Apply</button>
        @if($q || $status !== 'active')
            <a href="{{ route('corex.settings.rental-applications.decline-reason-templates.index') }}" class="text-xs" style="color: var(--text-muted);">Clear</a>
        @endif
    </form>

    @php
        $sortLink = fn ($col) => route('corex.settings.rental-applications.decline-reason-templates.index', array_merge(
            request()->except('page'),
            ['sort' => $col, 'direction' => ($sort === $col && $direction === 'asc') ? 'desc' : 'asc']
        ));
        $sortIndicator = fn ($col) => $sort === $col ? ($direction === 'asc' ? ' ▲' : ' ▼') : '';
    @endphp

    <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('reason') }}" style="color: var(--text-muted);">Reason{{ $sortIndicator('reason') }}</a></th>
                    <th class="text-left px-4 py-2" style="color: var(--text-muted);">Guidance</th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('sort_order') }}" style="color: var(--text-muted);">Order{{ $sortIndicator('sort_order') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('created_at') }}" style="color: var(--text-muted);">Created{{ $sortIndicator('created_at') }}</a></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($templates as $template)
                <tr style="border-bottom: 1px solid var(--border);">
                    <td class="px-4 py-2 align-top" style="white-space: nowrap;">
                        {{ $template->reason }}
                        @if($template->trashed())
                            <span class="ds-badge ds-badge-muted">Archived</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 align-top" style="max-width: 480px; color: var(--text-secondary);">{{ \Illuminate\Support\Str::limit($template->guidance, 160) }}</td>
                    <td class="px-4 py-2 align-top">{{ $template->sort_order }}</td>
                    <td class="px-4 py-2 align-top">{{ $template->created_at->format('d M Y') }}</td>
                    <td class="px-4 py-2 align-top text-right whitespace-nowrap">
                        @if($template->trashed())
                            <form method="POST" action="{{ route('corex.settings.rental-applications.decline-reason-templates.restore', $template->id) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-xs" style="color: var(--ds-blue, #2563eb);">Restore</button>
                            </form>
                        @else
                            <button type="button" class="text-xs" style="color: var(--ds-blue, #2563eb);" @click="editOpenId = (editOpenId === {{ $template->id }} ? null : {{ $template->id }})">Edit</button>
                            <form method="POST" action="{{ route('corex.settings.rental-applications.decline-reason-templates.archive', $template) }}" class="inline" onsubmit="return confirm('Archive this decline reason template?');">
                                @csrf
                                <button type="submit" class="text-xs ml-2" style="color: var(--ds-red, #dc2626);">Archive</button>
                            </form>
                        @endif
                    </td>
                </tr>
                {{-- Edit — same expand-in-place mechanic as Create, same visual weight, per Johan's "a correction must be at least as easy to find as the mistake it fixes" standard. --}}
                @if(!$template->trashed())
                <tr x-show="editOpenId === {{ $template->id }}" x-cloak>
                    <td colspan="5" class="px-4 pb-3">
                        <form method="POST" action="{{ route('corex.settings.rental-applications.decline-reason-templates.update', $template) }}" class="rounded-md p-3 space-y-3" style="background: var(--surface-2, #f9fafb); border: 1px solid var(--border);">
                            @csrf
                            @method('PUT')
                            <div>
                                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Reason</label>
                                <input type="text" name="reason" value="{{ $template->reason }}" maxlength="255" required class="rounded-md px-3 py-2 text-sm w-full" style="border: 1px solid var(--border);">
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Guidance — general tips only, never a number, never a promise of approval</label>
                                <textarea name="guidance" rows="4" required class="rounded-md px-3 py-2 text-sm w-full" style="border: 1px solid var(--border);">{{ $template->guidance }}</textarea>
                            </div>
                            <div class="flex justify-end gap-2">
                                <button type="button" class="corex-btn-outline text-xs" @click="editOpenId = null">Cancel</button>
                                <button type="submit" class="corex-btn-primary text-xs">Save changes</button>
                            </div>
                        </form>
                    </td>
                </tr>
                @endif
                @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
                    @if($q)
                        No decline reason templates match this search. <a href="{{ route('corex.settings.rental-applications.decline-reason-templates.index') }}" style="color: var(--ds-blue, #2563eb);">Clear the search</a>.
                    @else
                        No decline reason templates yet.
                        <button type="button" class="ml-1" style="color: var(--ds-blue, #2563eb);" @click="addOpen = true">+ Add a template</button>
                    @endif
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $templates->links() }}
</div>
@endsection
