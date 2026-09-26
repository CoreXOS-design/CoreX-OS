{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

{{--
    AT-430 §3.3 — "Application checklist" settings screen. Agency admins
    create, rename, reorder and archive sections and items. Full CRUD floor
    (create/read/update/archive/restore, search, sort, empty state), same
    shape as decline-reason-templates/index.blade.php, extended to two
    levels. Derived items (Lease progress: Deposit paid, First rent paid,
    Occupied) render read-only with a "Derived from lease" badge — the
    controller refuses an edit for one outright; this screen never even
    offers the control.
--}}

@section('corex-content')
<div class="w-full space-y-5" x-data="{ addSectionOpen: false, editSectionId: null, addItemForSectionId: null, editItemId: null }">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Application Checklist</h1>
                <p class="text-xs" style="color: var(--text-muted);">The vetting checklist agents work through on the rental application review screen — sections and items, fully yours to edit. A new application always starts from a snapshot of this list; editing it never rewrites an application already in flight.</p>
            </div>
            <button type="button" class="corex-btn-primary text-xs" @click="addSectionOpen = !addSectionOpen">+ Add a section</button>
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

    <div x-show="addSectionOpen" x-cloak class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="POST" action="{{ route('corex.settings.rental-applications.checklist.sections.store') }}" class="flex items-end gap-2">
            @csrf
            <div class="flex-1">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Section name</label>
                <input type="text" name="name" value="{{ old('name') }}" maxlength="191" required placeholder="e.g. Vetting" class="rounded-md px-3 py-2 text-sm w-full" style="border: 1px solid var(--border);">
            </div>
            <button type="button" class="corex-btn-outline text-xs" @click="addSectionOpen = false">Cancel</button>
            <button type="submit" class="corex-btn-primary text-xs">Save section</button>
        </form>
    </div>

    <form method="GET" action="{{ route('corex.settings.rental-applications.checklist.index') }}" class="rounded-md p-4 flex flex-wrap items-end gap-3" style="background: var(--surface); border: 1px solid var(--border);">
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ $q }}" placeholder="Section or item name" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border); min-width: 260px;">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
            <select name="status" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <option value="active" @selected($status === 'active')>Active</option>
                <option value="archived" @selected($status === 'archived')>Archived</option>
                <option value="all" @selected($status === 'all')>All</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Sort</label>
            <select name="sort" class="rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                <option value="sort_order" @selected($sort === 'sort_order')>Order (default)</option>
                <option value="name" @selected($sort === 'name')>Name</option>
                <option value="created_at" @selected($sort === 'created_at')>Created</option>
            </select>
        </div>
        <input type="hidden" name="direction" value="{{ $direction }}">
        <button type="submit" class="corex-btn-primary text-xs">Apply</button>
        @if($q || $status !== 'active')
            <a href="{{ route('corex.settings.rental-applications.checklist.index') }}" class="text-xs" style="color: var(--text-muted);">Clear</a>
        @endif
    </form>

    @forelse($sections as $section)
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="px-4 py-3 flex items-center justify-between" style="border-bottom: 1px solid var(--border); background: var(--surface-2, #f9fafb);">
                <div class="flex items-center gap-2">
                    <span class="text-sm font-semibold" style="color: var(--text-primary);">{{ $section->name }}</span>
                    @if($section->trashed())
                        <span class="ds-badge ds-badge-muted">Archived</span>
                    @endif
                    <span class="text-xs" style="color: var(--text-muted);">{{ $section->items->count() }} item{{ $section->items->count() === 1 ? '' : 's' }}</span>
                </div>
                <div class="flex items-center gap-2 text-xs">
                    @if($section->trashed())
                        <form method="POST" action="{{ route('corex.settings.rental-applications.checklist.sections.restore', $section->id) }}">
                            @csrf
                            <button type="submit" style="color: var(--ds-blue, #2563eb);">Restore</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('corex.settings.rental-applications.checklist.sections.move', [$section, 'up']) }}">@csrf<button type="submit" style="color: var(--text-secondary);" title="Move up">&uarr;</button></form>
                        <form method="POST" action="{{ route('corex.settings.rental-applications.checklist.sections.move', [$section, 'down']) }}">@csrf<button type="submit" style="color: var(--text-secondary);" title="Move down">&darr;</button></form>
                        <button type="button" style="color: var(--ds-blue, #2563eb);" @click="editSectionId = (editSectionId === {{ $section->id }} ? null : {{ $section->id }})">Rename</button>
                        <button type="button" style="color: var(--ds-blue, #2563eb);" @click="addItemForSectionId = (addItemForSectionId === {{ $section->id }} ? null : {{ $section->id }})">+ Add item</button>
                        <form method="POST" action="{{ route('corex.settings.rental-applications.checklist.sections.archive', $section) }}" onsubmit="return confirm('Archive this section and every item inside it?');">
                            @csrf
                            <button type="submit" style="color: var(--ds-red, #dc2626);">Archive</button>
                        </form>
                    @endif
                </div>
            </div>

            <div x-show="editSectionId === {{ $section->id }}" x-cloak class="px-4 py-3" style="border-bottom: 1px solid var(--border); background: var(--surface-2, #f9fafb);">
                <form method="POST" action="{{ route('corex.settings.rental-applications.checklist.sections.update', $section) }}" class="flex items-end gap-2">
                    @csrf
                    @method('PUT')
                    <div class="flex-1">
                        <input type="text" name="name" value="{{ $section->name }}" maxlength="191" required class="rounded-md px-3 py-2 text-sm w-full" style="border: 1px solid var(--border);">
                    </div>
                    <button type="button" class="corex-btn-outline text-xs" @click="editSectionId = null">Cancel</button>
                    <button type="submit" class="corex-btn-primary text-xs">Save</button>
                </form>
            </div>

            <div x-show="addItemForSectionId === {{ $section->id }}" x-cloak class="px-4 py-3" style="border-bottom: 1px solid var(--border); background: var(--surface-2, #f9fafb);">
                <form method="POST" action="{{ route('corex.settings.rental-applications.checklist.items.store', $section) }}" class="space-y-2">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Item name</label>
                        <input type="text" name="name" maxlength="191" required placeholder="e.g. Reference obtained" class="rounded-md px-3 py-2 text-sm w-full" style="border: 1px solid var(--border);">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Help text (optional)</label>
                        <input type="text" name="help_text" maxlength="2000" class="rounded-md px-3 py-2 text-sm w-full" style="border: 1px solid var(--border);">
                    </div>
                    <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);">
                        <input type="hidden" name="note_required" value="0">
                        <input type="checkbox" name="note_required" value="1"> Require a note before this item can be marked done
                    </label>
                    <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);">
                        <input type="hidden" name="document_required" value="0">
                        <input type="checkbox" name="document_required" value="1"> Require a document before this item can be marked done
                    </label>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="corex-btn-outline text-xs" @click="addItemForSectionId = null">Cancel</button>
                        <button type="submit" class="corex-btn-primary text-xs">Save item</button>
                    </div>
                </form>
            </div>

            <div class="divide-y" style="border-color: var(--border);">
                @forelse($section->items as $item)
                    <div class="px-4 py-2 flex items-center justify-between gap-3" style="border-color: var(--border);">
                        <div class="min-w-0">
                            <span class="text-sm" style="color: var(--text-primary);">{{ $item->name }}</span>
                            @if($item->is_derived)
                                <span class="ds-badge ds-badge-info" style="font-size: 9px;" title="Ticks itself off the lease — cannot be turned into a manual item.">Derived from lease</span>
                            @endif
                            @if($item->note_required)
                                <span class="ds-badge ds-badge-muted" style="font-size: 9px;">Note required</span>
                            @endif
                            @if($item->document_required)
                                <span class="ds-badge ds-badge-muted" style="font-size: 9px;">Document required</span>
                            @endif
                            @if($item->trashed())
                                <span class="ds-badge ds-badge-muted">Archived</span>
                            @endif
                            @if($item->help_text)
                                <p class="text-xs" style="color: var(--text-muted);">{{ $item->help_text }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-2 text-xs flex-shrink-0">
                            @if($item->trashed())
                                <form method="POST" action="{{ route('corex.settings.rental-applications.checklist.items.restore', $item->id) }}">@csrf<button type="submit" style="color: var(--ds-blue, #2563eb);">Restore</button></form>
                            @else
                                <form method="POST" action="{{ route('corex.settings.rental-applications.checklist.items.move', [$item, 'up']) }}">@csrf<button type="submit" style="color: var(--text-secondary);" title="Move up">&uarr;</button></form>
                                <form method="POST" action="{{ route('corex.settings.rental-applications.checklist.items.move', [$item, 'down']) }}">@csrf<button type="submit" style="color: var(--text-secondary);" title="Move down">&darr;</button></form>
                                @unless($item->is_derived)
                                    <button type="button" style="color: var(--ds-blue, #2563eb);" @click="editItemId = (editItemId === {{ $item->id }} ? null : {{ $item->id }})">Edit</button>
                                @endunless
                                <form method="POST" action="{{ route('corex.settings.rental-applications.checklist.items.archive', $item) }}" onsubmit="return confirm('Archive this checklist item?');">
                                    @csrf
                                    <button type="submit" style="color: var(--ds-red, #dc2626);">Archive</button>
                                </form>
                            @endif
                        </div>
                    </div>
                    @if(!$item->is_derived && !$item->trashed())
                    <div x-show="editItemId === {{ $item->id }}" x-cloak class="px-4 pb-3" style="background: var(--surface-2, #f9fafb);">
                        <form method="POST" action="{{ route('corex.settings.rental-applications.checklist.items.update', $item) }}" class="space-y-2">
                            @csrf
                            @method('PUT')
                            <input type="text" name="name" value="{{ $item->name }}" maxlength="191" required class="rounded-md px-3 py-2 text-sm w-full" style="border: 1px solid var(--border);">
                            <input type="text" name="help_text" value="{{ $item->help_text }}" maxlength="2000" placeholder="Help text (optional)" class="rounded-md px-3 py-2 text-sm w-full" style="border: 1px solid var(--border);">
                            <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);">
                                <input type="hidden" name="note_required" value="0">
                                <input type="checkbox" name="note_required" value="1" @checked($item->note_required)> Require a note before this item can be marked done
                            </label>
                            <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);">
                                <input type="hidden" name="document_required" value="0">
                                <input type="checkbox" name="document_required" value="1" @checked($item->document_required)> Require a document before this item can be marked done
                            </label>
                            <div class="flex justify-end gap-2">
                                <button type="button" class="corex-btn-outline text-xs" @click="editItemId = null">Cancel</button>
                                <button type="submit" class="corex-btn-primary text-xs">Save changes</button>
                            </div>
                        </form>
                    </div>
                    @endif
                @empty
                    <div class="px-4 py-4 text-center text-xs" style="color: var(--text-muted);">No items in this section yet.</div>
                @endforelse
            </div>
        </div>
    @empty
        <div class="rounded-md p-8 text-center text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);">
            @if($q)
                No checklist sections match this search. <a href="{{ route('corex.settings.rental-applications.checklist.index') }}" style="color: var(--ds-blue, #2563eb);">Clear the search</a>.
            @else
                No checklist sections yet.
                <button type="button" class="ml-1" style="color: var(--ds-blue, #2563eb);" @click="addSectionOpen = true">+ Add a section</button>
            @endif
        </div>
    @endforelse
</div>
@endsection
