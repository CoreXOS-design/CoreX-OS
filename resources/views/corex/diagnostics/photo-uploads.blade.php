@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Photo Upload Report</h1>
                <p class="text-sm mt-1" style="color: var(--text-muted);">
                    What the phone said happened to each photo, next to what actually reached CoreX.
                </p>
            </div>
            <form method="GET" class="flex items-center gap-2">
                <input type="number" name="property" value="{{ $propertyId ?: '' }}" placeholder="Listing number"
                       class="text-sm w-40 rounded-md px-3 py-2" style="background: var(--corex-card-bg); border: 1px solid var(--corex-border); color: var(--text-primary);">
                <button class="corex-btn-primary text-sm">Look up</button>
                @if($propertyId)
                    <a href="{{ route('corex.diagnostics.photo-uploads') }}"
                       class="text-sm underline" style="color: var(--text-muted);">Clear</a>
                @endif
            </form>
        </div>
    </div>

    @if($propertyId && ! $property)
        <div class="corex-panel corex-panel-body text-sm">
            No listing {{ $propertyId }} in this agency.
        </div>
    @endif

    @if($property)
        {{-- Verdict, in four numbers --}}
        @php $s = $summary; @endphp
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            @foreach ([
                ['Taken on the phone', $s['captured'], 'var(--text-primary)'],
                ['Queued to upload',   $s['queued'],   'var(--text-primary)'],
                ['Reached CoreX',      $s['received'], '#10b981'],
                ['Never arrived',      $s['missing'],  $s['missing'] > 0 ? '#ef4444' : 'var(--text-muted)'],
            ] as [$label, $value, $colour])
                <div class="corex-kpi-card">
                    <div class="text-2xl font-bold" style="color: {{ $colour }};">{{ $value }}</div>
                    <div class="text-xs mt-1" style="color: var(--text-muted);">{{ $label }}</div>
                </div>
            @endforeach
        </div>

        @if($s['captured'] === 0)
            <div class="corex-panel corex-panel-body text-sm" style="color: var(--text-muted);">
                The phone hasn't reported anything for this listing. Either the photos were
                uploaded by a build that predates this report, or from the web. Anything the
                server received still shows below.
            </div>
        @endif

        {{-- Per photo --}}
        <div class="corex-panel">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left" style="color: var(--text-muted);">
                            <th class="px-4 py-3 font-semibold">#</th>
                            <th class="px-4 py-3 font-semibold">Taken</th>
                            <th class="px-4 py-3 font-semibold">Queued</th>
                            <th class="px-4 py-3 font-semibold">Reached CoreX</th>
                            <th class="px-4 py-3 font-semibold">Delay</th>
                            <th class="px-4 py-3 font-semibold">Room</th>
                            <th class="px-4 py-3 font-semibold">What happened</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($photos as $p)
                        <tr style="border-top: 1px solid var(--corex-border);">
                            <td class="px-4 py-3">{{ $p->index ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $p->captured_at?->format('H:i:s') ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $p->queued_at?->format('H:i:s') ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $p->received_at?->format('H:i:s') ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if($p->lag_seconds !== null)
                                    <span @class(['font-semibold' => $p->lag_seconds > 120])
                                          style="color: {{ $p->lag_seconds > 120 ? '#f59e0b' : 'var(--text-muted)' }};">
                                        {{ $p->lag_seconds < 60 ? $p->lag_seconds.'s' : round($p->lag_seconds / 60).'m' }}
                                    </span>
                                @else — @endif
                            </td>
                            <td class="px-4 py-3">{{ $p->room_tag ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @php $ok = $p->status === 'landed'; @endphp
                                <span class="px-2 py-1 rounded text-xs font-semibold"
                                      style="background: {{ $ok ? 'rgba(16,185,129,.15)' : 'rgba(239,68,68,.15)' }};
                                             color: {{ $ok ? '#10b981' : '#ef4444' }};">
                                    {{ $p->status }}
                                </span>
                                @if($p->error)
                                    <div class="text-xs mt-1" style="color: var(--text-muted);">{{ $p->error }}</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6 text-center" style="color: var(--text-muted);">
                            Nothing recorded for this listing yet.
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Recent shoots --}}
    @if(! $propertyId)
        <div class="corex-panel">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left" style="color: var(--text-muted);">
                            <th class="px-4 py-3 font-semibold">Date</th>
                            <th class="px-4 py-3 font-semibold">Listing</th>
                            <th class="px-4 py-3 font-semibold">Taken</th>
                            <th class="px-4 py-3 font-semibold">Reached CoreX</th>
                            <th class="px-4 py-3 font-semibold">Never arrived</th>
                            <th class="px-4 py-3 font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($shoots as $sh)
                        <tr style="border-top: 1px solid var(--corex-border);">
                            <td class="px-4 py-3">{{ $sh->day }}</td>
                            <td class="px-4 py-3">
                                {{ trim(($sh->property->street_number ?? '').' '.($sh->property->street_name ?? '')) ?: ($sh->property->title ?? 'Listing') }}
                                <span style="color: var(--text-muted);">· {{ $sh->property->suburb ?? '' }} · #{{ $sh->property_id }}</span>
                            </td>
                            <td class="px-4 py-3">{{ $sh->captured }}</td>
                            <td class="px-4 py-3">{{ $sh->received }}</td>
                            <td class="px-4 py-3">
                                <span class="font-semibold" style="color: {{ $sh->missing > 0 ? '#ef4444' : 'var(--text-muted)' }};">
                                    {{ $sh->missing }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <a href="{{ route('corex.diagnostics.photo-uploads', ['property' => $sh->property_id]) }}"
                                   class="underline">Open</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center" style="color: var(--text-muted);">
                            No shoots reported yet. The app starts reporting once the build carrying
                            photo reporting is installed.
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

</div>
@endsection
