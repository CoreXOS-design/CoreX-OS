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

@section('content')
<div class="p-6 space-y-4">
    <div class="flex items-center justify-between flex-wrap gap-2">
        <h1 class="text-lg font-semibold">Property History</h1>
        <div class="flex items-center gap-3">
            <a href="{{ route('corex.rentals.reports.index') }}" class="corex-btn-outline text-xs">← Back to Reports</a>
            @if($property)
            <a href="{{ route('corex.rentals.reports.property-history.print', ['property_id' => $property->id]) }}" target="_blank" class="corex-btn-outline text-xs">Print</a>
            <a href="{{ route('corex.rentals.reports.property-history.pdf', ['property_id' => $property->id]) }}" class="corex-btn-outline text-xs">PDF</a>
            {{-- rentals-reports.md §5 — the landlord property activity report (PDF only) is reached from here. --}}
            <a href="{{ route('corex.rentals.reports.landlord-activity.pdf', ['property_id' => $property->id]) }}" class="corex-btn-outline text-xs" data-qa="landlord-activity-pdf">Landlord activity report (PDF)</a>
            @endif
        </div>
    </div>

    <form method="GET" action="{{ route('corex.rentals.reports.property-history') }}" class="flex items-end gap-3">
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Property</label><br>
            <select name="property_id" onchange="this.form.submit()" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border); min-width:260px;">
                <option value="">Select a property…</option>
                @foreach($properties as $p)
                    <option value="{{ $p->id }}" @selected($property && $property->id === $p->id)>{{ $p->title }}</option>
                @endforeach
            </select>
        </div>
    </form>

    @if(!$property)
    <div class="rounded-md px-4 py-8 text-center text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);">
        Pick a property above to see its full rental history.
    </div>
    @elseif(empty($timeline))
    <div class="rounded-md px-4 py-8 text-center text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);">
        {{ $property->buildDisplayAddress() }} has no leases or rental activity on record yet.
    </div>
    @else
    <div class="space-y-3">
        @foreach($timeline as $block)
        <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="px-4 py-2 text-sm font-semibold flex justify-between flex-wrap gap-2" style="border-bottom: 1px solid var(--border);">
                <span>{{ $block['group'] }}</span>
                <span style="color: var(--text-muted); font-weight:400;">Tenant: {{ $block['tenant'] }} @if($block['rent'])· Rent: R {{ number_format((float) $block['rent'], 2) }}@endif</span>
            </div>
            <table class="w-full text-sm">
                <tbody>
                    @forelse($block['events'] as $event)
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td class="px-4 py-2" style="width:110px; color: var(--text-muted);">{{ $event['date'] ? \Illuminate\Support\Carbon::parse($event['date'])->format('Y-m-d') : '—' }}</td>
                        <td class="px-4 py-2" style="width:110px;"><span class="ds-badge ds-badge-muted">{{ $event['type'] }}</span></td>
                        <td class="px-4 py-2">{{ $event['description'] }}</td>
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
