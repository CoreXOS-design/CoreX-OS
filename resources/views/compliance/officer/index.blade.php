@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <a href="{{ route('compliance.rmcp.index') }}" class="text-xs no-underline" style="color: var(--text-muted);">&larr; RMCP</a>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Compliance Officer</h1>
                <p class="text-xs" style="color: var(--text-muted);">The appointed FICA compliance officer and previous appointments.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
                <a href="{{ route('compliance.officer.create') }}" class="corex-btn-primary text-xs inline-flex items-center gap-1.5" data-tour="comp-officer-appoint">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Appoint New
                </a>
            </div>
        </div>
    </div>

    <div data-tour="comp-officer-intro">
        {{-- No current CO warning --}}
        @if(!$currentOfficer)
        <div class="mb-4 px-4 py-3 text-sm font-semibold" style="background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid rgba(239,68,68,0.3); border-radius:6px; color:var(--ds-crimson);">
            No compliance officer is currently appointed. Appoint one immediately to remain FICA compliant.
        </div>
        @endif

        {{-- Current officer --}}
        @if($currentOfficer)
        <div class="mb-6 p-4 border" style="background:var(--surface); border-color:var(--border, #e5e7eb); border-radius:6px;">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-xs font-semibold uppercase mb-2" style="color:var(--brand-icon); letter-spacing:0.05em;">Current Compliance Officer</h3>
                    <p class="text-lg font-bold" style="color:var(--text-primary, #1f2937);">{{ $currentOfficer->full_name }}</p>
                    <p class="text-sm mt-1" style="color:var(--text-muted);">{{ $currentOfficer->title }}</p>
                    <div class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-sm" style="color:var(--text-muted);">
                        @if($currentOfficer->id_number)
                        <p>ID: {{ $currentOfficer->id_number }}</p>
                        @endif
                        @if($currentOfficer->cell)
                        <p>Cell: {{ $currentOfficer->cell }}</p>
                        @endif
                        @if($currentOfficer->email)
                        <p>Email: {{ $currentOfficer->email }}</p>
                        @endif
                        <p>Appointed: {{ $currentOfficer->appointed_on->format('d M Y') }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('compliance.officer.edit', $currentOfficer) }}" class="text-xs font-semibold px-3 py-1.5" style="border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-secondary, #6b7280);">Edit</a>
                    <form method="POST" action="{{ route('compliance.officer.end', $currentOfficer) }}" onsubmit="return confirm('End this officer\'s appointment? This cannot be undone.');">
                        @csrf
                        <button type="submit" class="text-xs font-semibold px-3 py-1.5" style="border:1px solid rgba(239,68,68,0.3); border-radius:6px; color:var(--ds-crimson);">End Appointment</button>
                    </form>
                </div>
            </div>
        </div>
        @endif

        {{-- Historical officers --}}
        @if($historicalOfficers->isNotEmpty())
        <div x-data="{ showHistory: false }">
            <button @click="showHistory = !showHistory" class="text-sm font-semibold mb-3 flex items-center gap-1" style="color:var(--text-muted);">
                <svg class="w-4 h-4 transition-transform" :class="showHistory && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7"/></svg>
                Previous Officers ({{ $historicalOfficers->count() }})
            </button>

            <div x-show="showHistory" x-cloak class="overflow-x-auto rounded-md" style="background: var(--surface); border:1px solid var(--border);">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Name</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Title</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Appointed</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Ended</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($historicalOfficers as $officer)
                        <tr>
                            <td class="px-4 py-3">{{ $officer->full_name }}</td>
                            <td class="px-4 py-3" style="color:var(--text-muted);">{{ $officer->title }}</td>
                            <td class="px-4 py-3" style="color:var(--text-muted);">{{ $officer->appointed_on->format('d M Y') }}</td>
                            <td class="px-4 py-3" style="color:var(--text-muted);">{{ $officer->ended_on->format('d M Y') }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>
</div>
@endsection
