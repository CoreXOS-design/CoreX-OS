@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Compliance Officer" :back-route="route('compliance.rmcp.index')" back-label="RMCP" :flush="true">
        <x-slot:actions>
            <a href="{{ route('compliance.officer.create') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold transition" style="background:var(--brand-icon); color:var(--text-primary); border-radius:6px;">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Appoint New
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        {{-- No current CO warning --}}
        @if(!$currentOfficer)
        <div class="mb-4 px-4 py-3 text-sm font-semibold" style="background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid rgba(239,68,68,0.3); border-radius:6px; color:var(--ds-crimson);">
            No compliance officer is currently appointed. Appoint one immediately to remain FICA compliant.
        </div>
        @endif

        {{-- Current officer --}}
        @if($currentOfficer)
        <div class="mb-6 p-4 bg-white border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-xs font-semibold uppercase mb-2" style="color:var(--brand-icon); letter-spacing:0.05em;">Current Compliance Officer</h3>
                    <p class="text-lg font-bold" style="color:var(--text-primary, #1f2937);">{{ $currentOfficer->full_name }}</p>
                    <p class="text-sm mt-1" style="color:#64748b;">{{ $currentOfficer->title }}</p>
                    <div class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-sm" style="color:#64748b;">
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
            <button @click="showHistory = !showHistory" class="text-sm font-semibold mb-3 flex items-center gap-1" style="color:#64748b;">
                <svg class="w-4 h-4 transition-transform" :class="showHistory && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7"/></svg>
                Previous Officers ({{ $historicalOfficers->count() }})
            </button>

            <div x-show="showHistory" x-cloak class="overflow-x-auto" style="border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                <table class="w-full text-sm" style="">
                    <thead>
                        <tr style="background:var(--surface-alt, #f8fafc); border-bottom:1px solid var(--border, #e5e7eb);">
                            <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Name</th>
                            <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Title</th>
                            <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Appointed</th>
                            <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Ended</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($historicalOfficers as $officer)
                        <tr style="border-bottom:1px solid var(--border, #f1f5f9);">
                            <td class="px-4 py-3">{{ $officer->full_name }}</td>
                            <td class="px-4 py-3" style="color:#64748b;">{{ $officer->title }}</td>
                            <td class="px-4 py-3" style="color:#64748b;">{{ $officer->appointed_on->format('d M Y') }}</td>
                            <td class="px-4 py-3" style="color:#64748b;">{{ $officer->ended_on->format('d M Y') }}</td>
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
