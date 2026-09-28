{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Sales &amp; Rentals — Current Financial Year</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    Item (j) — every property active and advertised at any point in {{ $rangeLabel }}{{ $isCustomRange ? ' (custom range)' : '' }}.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.ppra-inspection-pack.sales-rentals.pdf', request()->query()) }}" class="corex-btn-outline text-xs">Export PDF</a>
                <a href="{{ route('admin.ppra-inspection-pack.sales-rentals.csv', request()->query()) }}" class="corex-btn-outline text-xs">Export CSV</a>
                <a href="{{ route('admin.ppra-inspection-pack.index') }}" class="corex-btn-outline text-xs">Back to checklist</a>
            </div>
        </div>
    </div>

    <div class="rounded-md p-4" style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 8%, transparent); border:1px solid var(--border);">
        <p class="text-xs" style="color:var(--text-secondary);">
            "Advertised" is derived from Property24/PrivateProperty activation and the agency's own website syndication.
            P24/PrivateProperty carry only the MOST RECENT activation date, not a full on/off history — a property advertised,
            pulled, and re-advertised within this financial year still correctly counts as advertised, but multiple separate
            windows within the year cannot be reconstructed from those two columns alone.
        </p>
    </div>

    <form method="GET" class="flex flex-wrap items-end gap-3 rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Search (address)</label>
            <input type="text" name="search" value="{{ request('search') }}" class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Custom from</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Custom to</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="text-sm rounded-md px-3 py-1.5" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
        </div>
        <button type="submit" class="corex-btn-primary text-xs">Filter</button>
        @if($isCustomRange)
            <a href="{{ route('admin.ppra-inspection-pack.sales-rentals') }}" class="text-xs font-semibold" style="color:var(--brand-icon,#0ea5e9);">Clear custom range (back to FY default)</a>
        @endif
    </form>

    @foreach(['Sales' => $salesPage, 'Rentals' => $rentalsPage] as $label => $page)
    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <div class="px-4 py-2.5" style="background:var(--surface-2); border-bottom:1px solid var(--border);">
            <h2 class="text-sm font-bold" style="color:var(--text-primary);">{{ $label }} ({{ $page->total() }})</h2>
        </div>
        @if($page->isEmpty())
            <div class="py-8 px-6 text-center">
                <p class="text-sm" style="color:var(--text-muted);">No {{ strtolower($label) }} were active and advertised in this window.</p>
            </div>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr style="border-bottom:1px solid var(--border);">
                        <th class="text-left px-4 py-2 text-xs font-semibold" style="color:var(--text-muted);">Address</th>
                        <th class="text-left px-4 py-2 text-xs font-semibold" style="color:var(--text-muted);">Suburb</th>
                        <th class="text-left px-4 py-2 text-xs font-semibold" style="color:var(--text-muted);">Listed Date</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($page as $p)
                    <tr style="border-bottom:1px solid var(--border);">
                        <td class="px-4 py-2" style="color:var(--text-primary);">{{ $p->address }}</td>
                        <td class="px-4 py-2" style="color:var(--text-secondary);">{{ $p->suburb ?? '—' }}</td>
                        <td class="px-4 py-2" style="color:var(--text-secondary);">{{ optional($p->listed_date)->format('d M Y') ?? '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="px-4 py-3" style="border-top:1px solid var(--border);">{{ $page->links() }}</div>
        @endif
    </div>
    @endforeach

</div>
@endsection
