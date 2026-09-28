{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Mandate / MDF / FICA Register</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    Item (m) — every active advertised listing, gap-monitoring feed for the checklist's live status. Distinct from the pack's sampled evidence for item (m).
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.ppra-inspection-pack.mandate-register.zip', request()->query()) }}" class="corex-btn-outline text-xs">Download ZIP of all mandates + MDFs</a>
                <a href="{{ route('admin.ppra-inspection-pack.index') }}" class="corex-btn-outline text-xs">Back to checklist</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background:color-mix(in srgb, #15803d 10%, transparent); color:#15803d; border:1px solid color-mix(in srgb, #15803d 25%, transparent);">
            {{ session('success') }}
        </div>
    @endif

    <form method="GET" class="flex flex-wrap items-end gap-3 rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Address, suburb, or agent"
                   class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Branch</label>
            <select name="branch_id" class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <option value="">All</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Status</label>
            <select name="status" class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <option value="all" @selected(request('status','all')==='all')>All</option>
                <option value="missing_mandate" @selected(request('status')==='missing_mandate')>Missing mandate</option>
                <option value="missing_mdf" @selected(request('status')==='missing_mdf')>Missing MDF</option>
                <option value="missing_fica" @selected(request('status')==='missing_fica')>Missing FICA</option>
                <option value="all_clear" @selected(request('status')==='all_clear')>All clear</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">From</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">To</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Sort</label>
            <select name="sort" class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <option value="address" @selected(request('sort','address')==='address')>Address</option>
                <option value="status" @selected(request('sort')==='status')>Status (gaps first)</option>
                <option value="listed_date" @selected(request('sort')==='listed_date')>Listed date</option>
            </select>
        </div>
        <label class="flex items-center gap-1.5 text-xs" style="color:var(--text-secondary);">
            <input type="checkbox" name="advertised_only" value="1" @checked(request('advertised_only','1') !== '0')> Advertised only
        </label>
        <button type="submit" class="corex-btn-primary text-xs">Filter</button>
    </form>

    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        @if($rows->isEmpty())
            <div class="py-12 px-6 text-center">
                <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">
                    {{ request()->hasAny(['search','branch_id','status','date_from','date_to']) ? 'No active listings match this filter' : 'This agency has no active listings yet' }}
                </h3>
                <p class="text-sm" style="color:var(--text-muted);">
                    {{ request()->hasAny(['search','branch_id','status','date_from','date_to']) ? 'Try a different search, branch, or status.' : 'Active advertised listings will appear here once the agency has some.' }}
                </p>
            </div>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Address</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Agent</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Mandate</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">MDF</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">FICA</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Advertised</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Listed</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                    <tr style="border-bottom:1px solid var(--border);">
                        <td class="px-4 py-3 font-semibold" style="color:var(--text-primary);">
                            <a href="{{ route('corex.properties.show', $row->property->id) }}" style="color:inherit;">{{ $row->property->address ?? ('Property #' . $row->property->id) }}</a>
                        </td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">{{ optional($row->property->agent)->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-center">{!! $row->mandate ? '<span style="color:#15803d;">&check;</span>' : '<span style="color:var(--ds-crimson,#c41e3a);">&cross;</span>' !!}</td>
                        <td class="px-4 py-3 text-center">{!! $row->mdf ? '<span style="color:#15803d;">&check;</span>' : '<span style="color:var(--ds-crimson,#c41e3a);">&cross;</span>' !!}</td>
                        <td class="px-4 py-3 text-center">{!! $row->fica ? '<span style="color:#15803d;">&check;</span>' : '<span style="color:var(--ds-crimson,#c41e3a);">&cross;</span>' !!}</td>
                        <td class="px-4 py-3 text-center">{!! $row->advertised ? '<span style="color:#15803d;">&check;</span>' : '<span style="color:var(--text-muted);">&cross;</span>' !!}</td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">{{ optional($row->property->listed_date)->format('d M Y') ?? '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="px-4 py-3" style="border-top:1px solid var(--border);">
                {{ $rows->links() }}
            </div>
        @endif
    </div>

</div>
@endsection
