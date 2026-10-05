{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">
                    {{ $principalOnly ? 'Principal Practitioner(s)' : 'Practitioner Register' }}
                </h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    {{ $principalOnly
                        ? 'Item (c) — the principal\'s own FFC certificate, sourced from their uploaded documents.'
                        : 'Item (f) — every active agent, branch manager, and admin, with their FFC status.' }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($principalOnly)
                    <a href="{{ route('admin.ppra-inspection-pack.practitioners') }}" class="corex-btn-outline text-xs">Full roster</a>
                @else
                    <a href="{{ route('admin.ppra-inspection-pack.practitioner-register.pdf') }}" class="corex-btn-outline text-xs">Export PDF</a>
                    <a href="{{ route('admin.ppra-inspection-pack.practitioner-register.csv') }}" class="corex-btn-outline text-xs">Export CSV</a>
                @endif
                <a href="{{ route('admin.ppra-inspection-pack.index') }}" class="corex-btn-outline text-xs">Back to checklist</a>
            </div>
        </div>
    </div>

    <form method="GET" class="flex flex-wrap items-end gap-3 rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
        @if($principalOnly)
            <input type="hidden" name="principal" value="1">
        @endif
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Name or designation"
                   class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
        </div>
        @unless($principalOnly)
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Role</label>
            <select name="role" class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <option value="">All</option>
                @foreach($roleOptions as $roleOption)
                    <option value="{{ $roleOption }}" @selected(request('role')===$roleOption)>{{ ucwords(str_replace('_', ' ', $roleOption)) }}</option>
                @endforeach
            </select>
        </div>
        @endunless
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">FFC Status</label>
            <select name="status" class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <option value="">All</option>
                <option value="green" @selected(request('status')==='green')>Valid</option>
                <option value="amber" @selected(request('status')==='amber')>Expiring / Pending</option>
                <option value="red" @selected(request('status')==='red')>Missing / Expired</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Sort</label>
            <select name="sort" class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <option value="status" @selected(request('sort', 'status')==='status')>Status (worst first)</option>
                <option value="name" @selected(request('sort')==='name')>Name</option>
            </select>
        </div>
        <button type="submit" class="corex-btn-primary text-xs">Filter</button>
    </form>

    @php
        $colourMap = [
            'green' => ['bg' => 'color-mix(in srgb, #15803d 12%, transparent)', 'fg' => '#15803d'],
            'amber' => ['bg' => 'color-mix(in srgb, var(--ds-amber,#f59e0b) 15%, transparent)', 'fg' => 'var(--ds-amber,#f59e0b)'],
            'red'   => ['bg' => 'color-mix(in srgb, var(--ds-crimson,#c41e3a) 12%, transparent)', 'fg' => 'var(--ds-crimson,#c41e3a)'],
        ];
    @endphp

    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        @if($roster->isEmpty())
            <div class="py-12 px-6 text-center">
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">
                    {{ request()->hasAny(['search','role','status'])
                        ? 'No practitioners match this filter'
                        : ($principalOnly ? 'No principal practitioner identified' : 'No active practitioners found') }}
                </h3>
                <p class="text-sm" style="color:var(--text-muted);">
                    {{ request()->hasAny(['search','role','status'])
                        ? 'Try a different search, role, or status.'
                        : ($principalOnly ? 'Set "Principal Property Practitioner" on a user\'s profile (Admin → Users → Edit) to add them here.' : 'No active agent, branch manager, or admin users on this agency.') }}
                </p>
            </div>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Name</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Role</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Designation</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">FFC Number</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Status</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Expiry</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Certificate</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($roster as $row)
                    @php $c = $colourMap[$row['ffc']['status']]; @endphp
                    <tr style="border-bottom:1px solid var(--border);">
                        <td class="px-4 py-3 font-semibold" style="color:var(--text-primary);">{{ $row['name'] }}</td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">{{ ucwords(str_replace('_', ' ', $row['role'])) }}</td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $row['designation'] ?? '—' }}</td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $row['ffc_number'] ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full" style="background:{{ $c['bg'] }}; color:{{ $c['fg'] }};">
                                {{ $row['ffc']['label'] }}
                            </span>
                        </td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">
                            {{ $row['ffc']['expiry_date'] ? \Illuminate\Support\Carbon::parse($row['ffc']['expiry_date'])->format('d M Y') : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if($row['ffc']['document'])
                                <a href="{{ route('user-documents.download', $row['ffc']['document']->id) }}" class="text-xs font-semibold" style="color:var(--brand-icon,#0ea5e9);">View / Download</a>
                            @else
                                <span class="text-xs" style="color:var(--text-muted);">Not uploaded</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="px-4 py-3" style="border-top:1px solid var(--border);">
                {{ $roster->links() }}
            </div>
        @endif
    </div>

</div>
@endsection
