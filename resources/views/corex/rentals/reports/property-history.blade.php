@extends('layouts.corex')

{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

    .ai/specs/rentals-reports.md §4.7 — pick a property, see every lease on
    it with the faults/work-orders/inspections inside each stay, plus
    property-level items between tenancies. Per the task brief this is a
    single-property timeline (differs from the original spec wording of
    "every property in scope at once" — the brief wins; reported in the spec
    update). No Alpine on this screen.
--}}

@section('corex-content')
<div class="w-full space-y-5">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner" data-tour="rops-ph-intro">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Property History</h1>
                <p class="text-xs" style="color: var(--text-muted);">Pick a property to see every lease on it, with the faults, work orders and inspections inside each stay.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('corex.rentals.reports.index') }}" class="corex-btn-outline text-xs">← Back to Reports</a>
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
                @if($property)
                <a href="{{ route('corex.rentals.reports.property-history.print', ['property_id' => $property->id]) }}" target="_blank" class="corex-btn-outline text-xs" data-tour="rops-ph-print">Print</a>
                <a href="{{ route('corex.rentals.reports.property-history.pdf', ['property_id' => $property->id]) }}" class="corex-btn-outline text-xs">PDF</a>
                {{-- rentals-reports.md §5 — the landlord property activity report (PDF only) is reached from here. --}}
                <a href="{{ route('corex.rentals.reports.landlord-activity.pdf', ['property_id' => $property->id]) }}" class="corex-btn-outline text-xs" data-qa="landlord-activity-pdf" data-tour="rops-ph-landlord">Landlord activity report (PDF)</a>
                @endif
            </div>
        </div>
    </div>

    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
    <form method="GET" action="{{ route('corex.rentals.reports.property-history') }}" class="flex flex-wrap items-end gap-3">
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Property</label>
            <select name="property_id" data-tour="rops-ph-property" onchange="this.form.submit()" class="list-header-filter text-sm {{ $property ? 'is-active' : '' }}" style="min-width:260px;">
                <option value="">Select a property…</option>
                @foreach($properties as $p)
                    <option value="{{ $p->id }}" @selected($property && $property->id === $p->id)>{{ $p->title }}</option>
                @endforeach
            </select>
        </div>
    </form>
    </div>

    @if(!$property)
    <div class="rounded-md px-6 py-12 text-center text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);">
        Pick a property above to see its full rental history.
    </div>
    @elseif(empty($timeline))
    <div class="rounded-md px-6 py-12 text-center text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);">
        {{ $property->buildDisplayAddress() }} has no leases or rental activity on record yet.
    </div>
    @else
    <div class="space-y-3" data-tour="rops-ph-timeline">
        @foreach($timeline as $block)
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" @if($loop->first) data-tour="rops-ph-block" @endif>
            <div class="px-4 py-3 text-sm font-semibold flex justify-between flex-wrap gap-2" style="background: var(--surface-2); color: var(--text-primary); border-bottom: 1px solid var(--border);">
                <span>{{ $block['group'] }}</span>
                <span style="color: var(--text-muted); font-weight:400;">Tenant: {{ $block['tenant'] }} @if($block['rent'])· Rent: R {{ number_format((float) $block['rent'], 2) }}@endif</span>
            </div>
            <table class="w-full text-sm">
                <tbody>
                    @forelse($block['events'] as $event)
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td class="px-4 py-3" style="width:110px; color: var(--text-muted);">{{ $event['date'] ? \Illuminate\Support\Carbon::parse($event['date'])->format('Y-m-d') : '—' }}</td>
                        <td class="px-4 py-3" style="width:110px;"><span class="ds-badge ds-badge-pill ds-badge-muted" @if($loop->first) data-tour="rops-ph-event" @endif>{{ $event['type'] }}</span></td>
                        <td class="px-4 py-3">{{ $event['description'] }}</td>
                    </tr>
                    @empty
                    <tr><td class="px-4 py-4 text-center text-sm" style="color: var(--text-muted);">Nothing recorded for this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @endforeach
    </div>
    @endif
</div>
@endsection
