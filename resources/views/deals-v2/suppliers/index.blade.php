{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

{{-- WS2 (AT-158 / DR2, D2) — reusable agency supplier directory settings. --}}
@section('corex-content')
<div class="w-full space-y-5" x-data="{ showAdd: false }">

    {{-- Page header (branded — §2.4 Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Supplier Directory</h1>
                <p class="text-sm text-white/60">
                    Reusable service providers (electrician for the COC, entomologist, attorneys, bond originator…).
                    Pick them on a deal, or add "the one we always use" once here for reuse.
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <button type="button" @click="showAdd = !showAdd" class="corex-btn-primary text-sm inline-flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    Add provider
                </button>
            </div>
        </div>
    </div>

    {{-- Flash / validation alerts (§3.9) --}}
    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Add form (§3.6 token-styled inputs) --}}
    <div x-show="showAdd" x-cloak
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
         class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Add a provider to the directory</div>
        <form method="POST" action="{{ route('deals-v2.suppliers.store') }}" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @csrf
            <div>
                <label for="sp-name" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Name <span class="text-red-500">*</span></label>
                <input id="sp-name" name="name" required maxlength="191"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                       placeholder="e.g. Sparky Electrical">
            </div>
            <div>
                <label for="sp-specialty" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Specialty <span class="text-red-500">*</span></label>
                <select id="sp-specialty" name="specialty" required
                        class="w-full rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    @foreach($specialties as $s)
                        <option value="{{ $s }}">{{ ucwords(str_replace('_', ' ', $s)) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="sp-company" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Company</label>
                <input id="sp-company" name="company" maxlength="191"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label for="sp-email" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Email</label>
                <input id="sp-email" name="email" type="email" maxlength="191"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label for="sp-phone" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Phone</label>
                <input id="sp-phone" name="phone" maxlength="50"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div class="flex items-end">
                <label class="inline-flex items-center gap-2 text-sm pb-2" style="color: var(--text-secondary);">
                    <input type="checkbox" name="is_preferred" value="1" class="rounded" style="accent-color: var(--brand-button, #0ea5e9);">
                    Preferred for this specialty
                </label>
            </div>
            <div class="md:col-span-3 flex items-center gap-2">
                <button type="submit" class="corex-btn-primary text-sm">Save to directory</button>
                <button type="button" @click="showAdd = false" class="corex-btn-outline text-sm">Cancel</button>
            </div>
        </form>
    </div>

    {{-- Directory table (§3.7) --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Name</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Specialty</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Contact</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($providers as $p)
                        <tr style="{{ $p->is_active ? '' : 'opacity:0.55;' }}">
                            <td class="px-4 py-3">
                                <span class="font-medium" style="color: var(--text-primary);">{{ $p->name }}</span>
                                @if($p->is_preferred)<span class="ds-badge ds-badge-success ml-2">Preferred</span>@endif
                                @if($p->company)<div class="text-[11px]" style="color: var(--text-muted);">{{ $p->company }}</div>@endif
                            </td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ ucwords(str_replace('_', ' ', $p->specialty)) }}</td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">
                                {{ $p->email ?: '—' }}@if($p->phone)<div class="text-[11px]" style="color: var(--text-muted);">{{ $p->phone }}</div>@endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="ds-badge {{ $p->is_active ? 'ds-badge-info' : 'ds-badge-default' }}">{{ $p->is_active ? 'Active' : 'Inactive' }}</span>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @if($p->is_active && !$p->is_preferred)
                                    <form method="POST" action="{{ route('deals-v2.suppliers.preferred', $p) }}" class="inline">
                                        @csrf<button type="submit" class="text-xs font-semibold no-underline hover:underline" style="color: var(--brand-icon, #0ea5e9);">Set preferred</button>
                                    </form>
                                @endif
                                @if($p->is_active)
                                    <form method="POST" action="{{ route('deals-v2.suppliers.deactivate', $p) }}" class="inline ml-3"
                                          onsubmit="return confirm('Deactivate {{ $p->name }}? Historic deals keep resolving; only new pickers hide it.');">
                                        @csrf<button type="submit" class="text-xs font-semibold no-underline hover:underline" style="color: var(--ds-crimson, #c41e3a);">Deactivate</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                No providers yet. Add one above, or create inline while filling a deal.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
