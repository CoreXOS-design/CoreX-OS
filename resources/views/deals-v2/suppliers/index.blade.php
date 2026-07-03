@extends('layouts.corex')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- WS2 (AT-158 / DR2, D2) — reusable agency supplier directory settings. --}}
@section('corex-content')
<div class="w-full space-y-5" x-data="{ showAdd: false }">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold" style="color: var(--text-primary);">Supplier Directory</h1>
            <p class="text-xs" style="color: var(--text-muted);">
                Reusable service providers (electrician for the COC, entomologist, attorneys, bond originator…).
                Pick them on a deal, or add "the one we always use" once here for reuse.
            </p>
        </div>
        <button type="button" @click="showAdd = !showAdd" class="corex-btn-primary">Add provider</button>
    </div>

    @if(session('success'))
        <div class="ds-alert ds-alert-success text-sm">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="ds-alert ds-alert-danger text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- Add form --}}
    <div x-show="showAdd" x-cloak class="rounded-lg p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="POST" action="{{ route('deals-v2.suppliers.store') }}" class="grid grid-cols-1 md:grid-cols-3 gap-3">
            @csrf
            <label class="text-xs" style="color: var(--text-muted);">Name
                <input name="name" required maxlength="191" class="corex-input mt-1 w-full" placeholder="e.g. Sparky Electrical">
            </label>
            <label class="text-xs" style="color: var(--text-muted);">Specialty
                <select name="specialty" required class="corex-input mt-1 w-full">
                    @foreach($specialties as $s)
                        <option value="{{ $s }}">{{ ucwords(str_replace('_', ' ', $s)) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-xs" style="color: var(--text-muted);">Company
                <input name="company" maxlength="191" class="corex-input mt-1 w-full">
            </label>
            <label class="text-xs" style="color: var(--text-muted);">Email
                <input name="email" type="email" maxlength="191" class="corex-input mt-1 w-full">
            </label>
            <label class="text-xs" style="color: var(--text-muted);">Phone
                <input name="phone" maxlength="50" class="corex-input mt-1 w-full">
            </label>
            <label class="text-xs flex items-center gap-2 mt-5" style="color: var(--text-muted);">
                <input type="checkbox" name="is_preferred" value="1"> Preferred for this specialty
            </label>
            <div class="md:col-span-3">
                <button type="submit" class="corex-btn-primary">Save to directory</button>
            </div>
        </form>
    </div>

    {{-- Directory --}}
    <div class="rounded-lg overflow-hidden" style="border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-3 text-xs font-medium" style="color: var(--text-muted);">Name</th>
                    <th class="text-left px-4 py-3 text-xs font-medium" style="color: var(--text-muted);">Specialty</th>
                    <th class="text-left px-4 py-3 text-xs font-medium" style="color: var(--text-muted);">Contact</th>
                    <th class="text-left px-4 py-3 text-xs font-medium" style="color: var(--text-muted);">Status</th>
                    <th class="text-right px-4 py-3 text-xs font-medium" style="color: var(--text-muted);">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($providers as $p)
                    <tr style="border-bottom: 1px solid var(--border); {{ $p->is_active ? '' : 'opacity:0.55;' }}">
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
                                    @csrf<button type="submit" class="text-xs no-underline hover:underline" style="color: var(--brand-icon, #0ea5e9);">Set preferred</button>
                                </form>
                            @endif
                            @if($p->is_active)
                                <form method="POST" action="{{ route('deals-v2.suppliers.deactivate', $p) }}" class="inline ml-3"
                                      onsubmit="return confirm('Deactivate {{ $p->name }}? Historic deals keep resolving; only new pickers hide it.');">
                                    @csrf<button type="submit" class="text-xs no-underline hover:underline" style="color: var(--ds-red, #ef4444);">Deactivate</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No providers yet. Add one above, or create inline while filling a deal.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
