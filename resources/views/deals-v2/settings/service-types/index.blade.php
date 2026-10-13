@extends('layouts.corex')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- AT-229 — agency-configurable COC / service-type list. Feeds the supplier work-order "service type" dropdown in the pipeline step-config.
     Johan, 13 Oct 2026 — every service type belongs to a GROUP the agency owns (Your trades / Property sales / its own); the supplier form groups its Trade dropdown by them. --}}

@section('corex-content')
@php
    $inputStyle = 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);';
    $sortUrl = fn ($col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc']);
    $arrow = fn ($col) => $sort === $col ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    $groupOptions = $groups->where('is_active', true);
@endphp
<div class="w-full space-y-5" x-data="{ showAdd: false, editId: null, editGroup: null, showGroups: {{ $errors->has('label') ? 'true' : 'false' }} }">

    {{-- Page header (branded — §2.4 Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">COC / Service Types</h1>
                <p class="text-sm text-white/60">
                    Your agency's certificate &amp; service list (Electrical, Beetle, Gas, Electric Fence, Plumbing, Security, Transfer Attorney…), kept in groups.
                    The groups are what the supplier form's Trade dropdown shows.
                </p>
            </div>
            <div class="flex gap-2">
                <button type="button" @click="showGroups = !showGroups" class="text-sm px-4 py-2 rounded font-medium" style="background: rgba(255,255,255,.15); color: #ffffff; border: 1px solid rgba(255,255,255,.4);" data-groups-toggle>Groups</button>
                <button type="button" @click="showAdd = !showAdd" class="text-sm px-4 py-2 rounded font-medium" style="background: #ffffff; color: var(--brand-default, #0b2a4a);">+ Add service type</button>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46;">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;" data-form-error>{{ $errors->first() }}</div>
    @endif

    {{-- Groups: add, rename, reorder, archive / restore --}}
    <div x-show="showGroups" x-cloak class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" data-groups-card>
        <div class="text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Groups</div>
        <div class="space-y-2">
            @foreach($groups as $g)
                <div class="flex flex-wrap items-center gap-2 text-sm" data-group-row="{{ $g->id }}">
                    <div class="flex items-center gap-1">
                        <form method="POST" action="{{ route('deals-v2.settings.service-types.groups.move', $g) }}">@csrf<input type="hidden" name="dir" value="up"><button type="submit" class="text-xs px-2 py-1 rounded" style="border:1px solid var(--border);color:var(--text-secondary);" title="Move up" @disabled($loop->first)>&uarr;</button></form>
                        <form method="POST" action="{{ route('deals-v2.settings.service-types.groups.move', $g) }}">@csrf<input type="hidden" name="dir" value="down"><button type="submit" class="text-xs px-2 py-1 rounded" style="border:1px solid var(--border);color:var(--text-secondary);" title="Move down" @disabled($loop->last)>&darr;</button></form>
                    </div>
                    <span x-show="editGroup !== {{ $g->id }}" class="font-medium" style="color: var(--text-primary);">{{ $g->label }}</span>
                    <span x-show="editGroup !== {{ $g->id }}" class="text-xs" style="color: var(--text-muted);">{{ $typeCounts[$g->id] ?? 0 }} service {{ ($typeCounts[$g->id] ?? 0) === 1 ? 'type' : 'types' }}</span>
                    <form x-show="editGroup === {{ $g->id }}" x-cloak method="POST" action="{{ route('deals-v2.settings.service-types.groups.update', $g) }}" class="flex items-center gap-2">
                        @csrf @method('PUT')
                        <input type="text" name="label" maxlength="100" required value="{{ $g->label }}" class="rounded-md text-sm px-3 py-1.5" style="{{ $inputStyle }}">
                        <button type="submit" class="text-xs px-3 py-1.5 rounded font-medium text-white" style="background: var(--brand-button, #0ea5e9);">Save</button>
                        <button type="button" @click="editGroup = null" class="text-xs px-2 py-1.5" style="color: var(--text-muted);">Cancel</button>
                    </form>
                    <button type="button" x-show="editGroup !== {{ $g->id }}" @click="editGroup = {{ $g->id }}" class="text-xs px-2 py-1 rounded" style="border:1px solid var(--border);color:var(--text-secondary);">Rename</button>
                    <form method="POST" action="{{ route('deals-v2.settings.service-types.groups.destroy', $g) }}" class="inline" x-show="editGroup !== {{ $g->id }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-xs px-2 py-1 rounded" style="border:1px solid #fecaca;color:#b91c1c;">Archive</button>
                    </form>
                </div>
            @endforeach
        </div>
        <form method="POST" action="{{ route('deals-v2.settings.service-types.groups.store') }}" class="flex flex-wrap items-end gap-2 pt-2" style="border-top: 1px solid var(--border);">
            @csrf
            <div>
                <label class="block text-xs mb-1" style="color: var(--text-muted);">New group</label>
                <input type="text" name="label" maxlength="100" required placeholder="e.g. Letting" class="rounded-md text-sm px-3 py-2" style="{{ $inputStyle }}">
            </div>
            <button type="submit" class="text-sm px-4 py-2 rounded font-medium text-white" style="background: var(--brand-button, #0ea5e9);">Add group</button>
        </form>
        @if($archivedGroups->isNotEmpty())
            <div class="pt-2 space-y-1" style="border-top: 1px solid var(--border);">
                <div class="text-xs" style="color: var(--text-muted);">Archived groups</div>
                @foreach($archivedGroups as $g)
                    <div class="flex items-center gap-3 text-sm">
                        <span style="color: var(--text-muted);">{{ $g->label }}</span>
                        <form method="POST" action="{{ route('deals-v2.settings.service-types.groups.restore', $g->id) }}">@csrf<button type="submit" class="text-xs px-2 py-1 rounded" style="border:1px solid var(--border);color:#0f766e;">Restore</button></form>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Add form --}}
    <div x-show="showAdd" x-cloak class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="POST" action="{{ route('deals-v2.settings.service-types.store') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div class="flex-1 min-w-[220px]">
                <label class="block text-xs mb-1" style="color: var(--text-muted);">Service type name</label>
                <input type="text" name="label" maxlength="100" required placeholder="e.g. Electric Fence COC" class="w-full rounded-md text-sm px-3 py-2 focus:outline-none" style="{{ $inputStyle }}">
            </div>
            <div>
                <label class="block text-xs mb-1" style="color: var(--text-muted);">Group <span style="color:#b91c1c;">*</span></label>
                <select name="group_id" required class="rounded-md text-sm px-3 py-2" style="{{ $inputStyle }}" data-add-group>
                    <option value="">Choose a group…</option>
                    @foreach($groupOptions as $g)<option value="{{ $g->id }}" @selected((int) old('group_id') === $g->id)>{{ $g->label }}</option>@endforeach
                </select>
            </div>
            <label class="inline-flex items-center gap-1.5 text-sm cursor-pointer pb-2" style="color: var(--text-secondary);">
                <input type="checkbox" name="is_active" value="1" checked class="rounded" style="accent-color: var(--brand-button, #0ea5e9);"> Active
            </label>
            <button type="submit" class="text-sm px-4 py-2 rounded font-medium text-white" style="background: var(--brand-button, #0ea5e9);">Add</button>
            <button type="button" @click="showAdd = false" class="text-sm px-3 py-2 rounded" style="color: var(--text-muted);">Cancel</button>
        </form>
    </div>

    {{-- Filter --}}
    <form method="GET" class="flex flex-wrap items-end gap-3">
        <div>
            <label for="st-group" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Group</label>
            <select id="st-group" name="group" onchange="this.form.submit()" class="rounded-md px-3 py-2 text-sm" style="{{ $inputStyle }}">
                <option value="">All groups</option>
                @foreach($groups as $g)<option value="{{ $g->id }}" @selected($groupFilter === $g->id)>{{ $g->label }}</option>@endforeach
            </select>
        </div>
        <input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="dir" value="{{ $dir }}">
        @if($groupFilter)<a href="{{ route('deals-v2.settings.service-types.index') }}" class="text-xs pb-2 underline" style="color: var(--text-muted);">Clear</a>@endif
    </form>

    {{-- List --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted);">
                    <th class="text-left px-4 py-2 font-medium"><a href="{{ $sortUrl('order') }}" class="no-underline" data-sort="order">Order{{ $arrow('order') }}</a></th>
                    <th class="text-left px-4 py-2 font-medium"><a href="{{ $sortUrl('label') }}" class="no-underline" data-sort="label">Service type{{ $arrow('label') }}</a></th>
                    <th class="text-left px-4 py-2 font-medium"><a href="{{ $sortUrl('group') }}" class="no-underline" data-sort="group">Group{{ $arrow('group') }}</a></th>
                    <th class="text-left px-4 py-2 font-medium"><a href="{{ $sortUrl('code') }}" class="no-underline" data-sort="code">Stored value{{ $arrow('code') }}</a></th>
                    <th class="text-left px-4 py-2 font-medium">Status</th>
                    <th class="text-right px-4 py-2 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($types as $t)
                    {{-- Read row --}}
                    <tr x-show="editId !== {{ $t->id }}" style="border-top: 1px solid var(--border); color: var(--text-primary);" data-type-row="{{ $t->code }}">
                        <td class="px-4 py-2" style="color: var(--text-muted);">{{ $t->sort_order }}</td>
                        <td class="px-4 py-2 font-medium">{{ $t->label }}</td>
                        <td class="px-4 py-2" data-type-group>{{ $t->group?->label ?? '—' }}</td>
                        <td class="px-4 py-2"><code class="text-xs" style="color: var(--text-muted);">{{ $t->code }}</code></td>
                        <td class="px-4 py-2">
                            @if($t->is_active)
                                <span class="text-xs px-2 py-0.5 rounded-full" style="background:#ecfdf5;color:#065f46;">Active</span>
                            @else
                                <span class="text-xs px-2 py-0.5 rounded-full" style="background:#f3f4f6;color:#6b7280;">Inactive</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <button type="button" @click="editId = {{ $t->id }}" class="text-xs px-2 py-1 rounded" style="border:1px solid var(--border);color:var(--text-secondary);">Edit</button>
                            <form method="POST" action="{{ route('deals-v2.settings.service-types.destroy', $t) }}" class="inline"
                                  onsubmit="return confirm('Archive “{{ $t->label }}”? It leaves the dropdown; steps already using it keep working.');">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs px-2 py-1 rounded" style="border:1px solid #fecaca;color:#b91c1c;">Archive</button>
                            </form>
                        </td>
                    </tr>
                    {{-- Edit row --}}
                    <tr x-show="editId === {{ $t->id }}" x-cloak style="border-top: 1px solid var(--border); background: var(--surface-2);">
                        <td colspan="6" class="px-4 py-3">
                            <form method="POST" action="{{ route('deals-v2.settings.service-types.update', $t) }}" class="flex flex-wrap items-end gap-3">
                                @csrf @method('PUT')
                                <div>
                                    <label class="block text-xs mb-1" style="color: var(--text-muted);">Order</label>
                                    <input type="number" name="sort_order" min="0" value="{{ $t->sort_order }}" class="w-20 rounded-md text-sm px-2 py-2" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                </div>
                                <div class="flex-1 min-w-[220px]">
                                    <label class="block text-xs mb-1" style="color: var(--text-muted);">Service type name</label>
                                    <input type="text" name="label" maxlength="100" required value="{{ $t->label }}" class="w-full rounded-md text-sm px-3 py-2" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    <p class="text-xs mt-1" style="color: var(--text-muted);">Stored value <code>{{ $t->code }}</code> stays fixed — renaming never breaks a configured step.</p>
                                </div>
                                <div>
                                    <label class="block text-xs mb-1" style="color: var(--text-muted);">Group <span style="color:#b91c1c;">*</span></label>
                                    <select name="group_id" required class="rounded-md text-sm px-3 py-2" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                        @foreach($groupOptions as $g)<option value="{{ $g->id }}" @selected((int) $t->group_id === $g->id)>{{ $g->label }}</option>@endforeach
                                    </select>
                                </div>
                                <label class="inline-flex items-center gap-1.5 text-sm cursor-pointer pb-2" style="color: var(--text-secondary);">
                                    <input type="checkbox" name="is_active" value="1" @checked($t->is_active) class="rounded" style="accent-color: var(--brand-button, #0ea5e9);"> Active
                                </label>
                                <button type="submit" class="text-sm px-4 py-2 rounded font-medium text-white" style="background: var(--brand-button, #0ea5e9);">Save</button>
                                <button type="button" @click="editId = null" class="text-sm px-3 py-2 rounded" style="color: var(--text-muted);">Cancel</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center" style="color: var(--text-muted);">No service types{{ $groupFilter ? ' in this group' : '' }} yet. Add one above.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Archived --}}
    @if($archived->isNotEmpty())
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="text-xs font-medium uppercase tracking-wider mb-2" style="color: var(--text-muted);">Archived</div>
        <div class="space-y-2">
            @foreach($archived as $t)
                <div class="flex items-center justify-between text-sm">
                    <span style="color: var(--text-muted);">{{ $t->label }} <code class="text-xs">{{ $t->code }}</code> &middot; {{ $t->group?->label ?? '—' }}</span>
                    <form method="POST" action="{{ route('deals-v2.settings.service-types.restore', $t->id) }}">
                        @csrf
                        <button type="submit" class="text-xs px-2 py-1 rounded" style="border:1px solid var(--border);color:#0f766e;">Restore</button>
                    </form>
                </div>
            @endforeach
        </div>
    </div>
    @endif

</div>
@endsection
