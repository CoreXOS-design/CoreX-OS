@extends('layouts.corex')

@section('corex-content')
@php
    $input = 'rounded-md px-3 py-2 text-sm';
    $inputStyle = 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);';
@endphp
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-bold text-white leading-tight">Title deed storage locations</h1>
            <a href="{{ route('corex.settings.title-deeds.edit') }}" class="text-xs font-semibold text-white/80">&larr; Title Deed settings</a>
        </div>
    </div>

    @if(session('success'))<div class="rounded-md px-4 py-3 text-sm font-medium" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);"><ul class="list-disc list-inside space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    @if($noAgency)
        <div class="rounded-md p-4 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">Switch into an agency to manage its storage locations.</div>
    @else
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="POST" action="{{ route('corex.settings.title-deeds.locations.store') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div class="flex-1 min-w-[200px]">
                <label for="loc-name" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">New location</label>
                <input id="loc-name" type="text" name="name" required maxlength="120" value="{{ old('name') }}" placeholder="e.g. Safe 2, Strongroom" class="w-full {{ $input }}" style="{{ $inputStyle }}">
            </div>
            @if($branches->count() > 1)
            <div>
                <label for="loc-branch" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Only for</label>
                <select id="loc-branch" name="branch_id" class="{{ $input }}" style="{{ $inputStyle }}">
                    <option value="">Every branch</option>
                    @foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                </select>
            </div>
            @endif
            <div>
                <label for="loc-sort" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Order</label>
                <input id="loc-sort" type="number" name="sort_order" min="0" max="9999" class="w-20 {{ $input }}" style="{{ $inputStyle }}">
            </div>
            <button type="submit" class="corex-btn-primary text-sm">Add</button>
        </form>
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" class="flex flex-wrap items-center gap-3 p-3" style="border-bottom: 1px solid var(--border);">
            <input type="text" name="q" value="{{ $search }}" placeholder="Search locations" maxlength="100" class="flex-1 min-w-[160px] {{ $input }}" style="{{ $inputStyle }}">
            <label class="text-xs flex items-center gap-1" style="color: var(--text-secondary);"><input type="checkbox" name="archived" value="1" @checked($showArchived) onchange="this.form.submit()"> Archived</label>
            <button type="submit" class="corex-btn-outline text-xs">Search</button>
        </form>
        <table class="min-w-full text-sm">
            <thead><tr style="background: var(--surface-2);">
                <th class="text-left px-4 py-2 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Location</th>
                @if($branches->count() > 1)<th class="text-left px-4 py-2 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Branch</th>@endif
                <th class="text-left px-4 py-2 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Order</th>
                <th></th>
            </tr></thead>
            <tbody>
            @forelse($locations as $loc)
                <tr style="border-top: 1px solid var(--border);" x-data="{ editing: false }">
                    @if($showArchived)
                        <td class="px-4 py-2" style="color: var(--text-secondary);">{{ $loc->name }}</td>
                        @if($branches->count() > 1)<td class="px-4 py-2" style="color: var(--text-secondary);">{{ $loc->branch?->name ?? 'Every branch' }}</td>@endif
                        <td class="px-4 py-2">{{ $loc->sort_order }}</td>
                        <td class="px-4 py-2 text-right">
                            <form method="POST" action="{{ route('corex.settings.title-deeds.locations.restore', $loc->id) }}">@csrf<button type="submit" class="text-xs font-semibold" style="color: var(--ds-green);">Restore</button></form>
                        </td>
                    @else
                        <td colspan="{{ $branches->count() > 1 ? 4 : 3 }}" class="px-4 py-2">
                            <form method="POST" action="{{ route('corex.settings.title-deeds.locations.update', $loc->id) }}" class="flex flex-wrap items-center gap-2">
                                @csrf @method('PUT')
                                <input type="text" name="name" value="{{ $loc->name }}" required maxlength="120" class="flex-1 min-w-[160px] {{ $input }}" style="{{ $inputStyle }}" aria-label="Location name">
                                @if($branches->count() > 1)
                                <select name="branch_id" class="{{ $input }}" style="{{ $inputStyle }}" aria-label="Branch">
                                    <option value="">Every branch</option>
                                    @foreach($branches as $b)<option value="{{ $b->id }}" @selected((int) $loc->branch_id === (int) $b->id)>{{ $b->name }}</option>@endforeach
                                </select>
                                @endif
                                <input type="number" name="sort_order" value="{{ $loc->sort_order }}" min="0" max="9999" class="w-20 {{ $input }}" style="{{ $inputStyle }}" aria-label="Order">
                                <button type="submit" class="corex-btn-outline text-xs">Save</button>
                            </form>
                        </td>
                    @endif
                    @unless($showArchived)
                    <td class="px-2 py-2 text-right whitespace-nowrap">
                        <form method="POST" action="{{ route('corex.settings.title-deeds.locations.destroy', $loc->id) }}">@csrf @method('DELETE')
                            <x-confirm-submit title="Archive this location" message="It stops being offered for new deeds. Deeds kept there keep showing it, and you can restore it later." confirm-label="Archive" :danger="true" class="corex-btn-outline text-xs">Archive</x-confirm-submit>
                        </form>
                    </td>
                    @endunless
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">
                    @if($search !== '') No locations match that search.
                    @elseif($showArchived) Nothing archived.
                    @else No storage locations yet. Add the first one above.
                    @endif
                </td></tr>
            @endforelse
            </tbody>
        </table>
        @if($locations->hasPages())<div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $locations->links() }}</div>@endif
    </div>
    @endif
</div>
@endsection
