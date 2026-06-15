@extends('layouts.corex')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
<div class="space-y-5">

    {{-- Page header (branded, Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Duplicate Cleanup Queue</h1>
                <p class="text-sm text-white/60">Review and resolve potential duplicate contacts detected across your agency.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="ds-badge ds-badge-warning">{{ number_format($clusters->total()) }} Pending</span>
            </div>
        </div>
    </div>

    {{-- Success flash --}}
    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background:color-mix(in srgb, var(--ds-green,#059669) 10%, transparent);
                    border:1px solid color-mix(in srgb, var(--ds-green,#059669) 30%, transparent);
                    color:var(--text-primary);">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="color:var(--ds-green,#059669);">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <div class="flex-1">{{ session('success') }}</div>
        </div>
    @endif

    @if($clusters->isEmpty())
        {{-- Empty state --}}
        <div class="rounded-md py-12 px-6 text-center" style="background:var(--surface); border:1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-icon,#0ea5e9);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No duplicate clusters detected</h3>
            <p class="text-sm mb-2" style="color:var(--text-muted);">Your contacts are clean — there are no pending duplicates to review.</p>
            <p class="text-xs" style="color:var(--text-muted);">
                Run <code class="rounded" style="background:var(--surface-2); padding:2px 6px;">php artisan contacts:detect-duplicates</code> to scan again.
            </p>
        </div>
    @else
        @foreach($clusters as $cluster)
            @php
                $contactIds = json_decode($cluster->contact_ids, true) ?? [];
                $clusterContacts = collect($contactIds)->map(fn($id) => $contacts->get($id))->filter();
            @endphp
            <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">

                {{-- Cluster header band --}}
                <div class="flex items-center justify-between gap-3 px-5 py-4"
                     style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                    <div class="min-w-0">
                        <h3 class="text-sm font-semibold truncate" style="color:var(--text-primary);">
                            {{ ucfirst($cluster->match_field) }} match
                        </h3>
                        <p class="text-xs mt-0.5 truncate" style="color:var(--text-muted);">
                            "{{ $cluster->match_value }}" · {{ number_format(count($contactIds)) }} contacts
                        </p>
                    </div>
                    <span class="ds-badge ds-badge-warning flex-shrink-0">Pending</span>
                </div>

                {{-- Cluster body --}}
                <div class="p-4">
                    <div class="space-y-2 mb-4">
                        @foreach($clusterContacts as $contact)
                            <div class="flex items-center gap-3 p-2 rounded-md" style="background:var(--surface-2);">
                                <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
                                     style="background:var(--brand-icon,#0ea5e9);">
                                    {{ strtoupper(substr($contact->first_name ?? '', 0, 1)) }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium truncate" style="color:var(--text-primary);">
                                        {{ $contact->full_name }}
                                    </div>
                                    <div class="text-xs truncate" style="color:var(--text-muted);">
                                        {{ $contact->phone }} · {{ $contact->email ?? 'No email' }}
                                        · Owner: {{ $contact->createdBy?->name ?? 'Unknown' }}
                                    </div>
                                </div>
                                <a href="{{ route('corex.contacts.show', $contact) }}" class="text-xs font-semibold flex-shrink-0" style="color:var(--brand-icon,#0ea5e9);">View</a>
                            </div>
                        @endforeach
                    </div>

                    <div class="flex items-center gap-2 flex-wrap">
                        <form method="POST" action="{{ route('command-center.admin.duplicate-cleanup.dismiss', $cluster->id) }}">
                            @csrf
                            <button type="submit" class="corex-btn-outline text-sm">Not Duplicate</button>
                        </form>
                        <button type="button" onclick="alert('Merge flow will be available in a follow-up release.')"
                                class="corex-btn-primary text-sm">
                            Merge (Coming Soon)
                        </button>
                    </div>
                </div>
            </div>
        @endforeach

        <div class="mt-4">{{ $clusters->links() }}</div>
    @endif
</div>
@endsection
