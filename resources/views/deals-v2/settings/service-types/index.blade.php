@extends('layouts.corex')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- AT-229 — agency-configurable COC / service-type list. Feeds the supplier
     work-order "service type" dropdown in the pipeline step-config. --}}

@section('corex-content')
<div class="w-full space-y-5" x-data="{ showAdd: false, editId: null }">

    {{-- Page header (branded — §2.4 Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">COC / Service Types</h1>
                <p class="text-sm text-white/60">
                    Your agency's certificate &amp; service list (Electrical, Beetle, Gas, Electric Fence, Plumbing…).
                    These are the options the supplier work-order dropdown offers on each pipeline step.
                </p>
            </div>
            <button type="button" @click="showAdd = !showAdd"
                    class="text-sm px-4 py-2 rounded font-medium" style="background: #ffffff; color: var(--brand-default, #0b2a4a);">
                + Add service type
            </button>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46;">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;">{{ $errors->first() }}</div>
    @endif

    {{-- Add form --}}
    <div x-show="showAdd" x-cloak class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="POST" action="{{ route('deals-v2.settings.service-types.store') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div class="flex-1 min-w-[220px]">
                <label class="block text-xs mb-1" style="color: var(--text-muted);">Service type name</label>
                <input type="text" name="label" maxlength="100" required placeholder="e.g. Electric Fence COC"
                       class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <label class="inline-flex items-center gap-1.5 text-sm cursor-pointer pb-2" style="color: var(--text-secondary);">
                <input type="checkbox" name="is_active" value="1" checked class="rounded" style="accent-color: var(--brand-button, #0ea5e9);"> Active
            </label>
            <button type="submit" class="text-sm px-4 py-2 rounded font-medium text-white" style="background: var(--brand-button, #0ea5e9);">Add</button>
            <button type="button" @click="showAdd = false" class="text-sm px-3 py-2 rounded" style="color: var(--text-muted);">Cancel</button>
        </form>
    </div>

    {{-- List --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted);">
                    <th class="text-left px-4 py-2 font-medium">Order</th>
                    <th class="text-left px-4 py-2 font-medium">Service type</th>
                    <th class="text-left px-4 py-2 font-medium">Stored value</th>
                    <th class="text-left px-4 py-2 font-medium">Status</th>
                    <th class="text-right px-4 py-2 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($types as $t)
                    {{-- Read row --}}
                    <tr x-show="editId !== {{ $t->id }}" style="border-top: 1px solid var(--border); color: var(--text-primary);">
                        <td class="px-4 py-2" style="color: var(--text-muted);">{{ $t->sort_order }}</td>
                        <td class="px-4 py-2 font-medium">{{ $t->label }}</td>
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
                        <td colspan="5" class="px-4 py-3">
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
                                <label class="inline-flex items-center gap-1.5 text-sm cursor-pointer pb-2" style="color: var(--text-secondary);">
                                    <input type="checkbox" name="is_active" value="1" @checked($t->is_active) class="rounded" style="accent-color: var(--brand-button, #0ea5e9);"> Active
                                </label>
                                <button type="submit" class="text-sm px-4 py-2 rounded font-medium text-white" style="background: var(--brand-button, #0ea5e9);">Save</button>
                                <button type="button" @click="editId = null" class="text-sm px-3 py-2 rounded" style="color: var(--text-muted);">Cancel</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center" style="color: var(--text-muted);">No service types yet. Add one above.</td></tr>
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
                    <span style="color: var(--text-muted);">{{ $t->label }} <code class="text-xs">{{ $t->code }}</code></span>
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
