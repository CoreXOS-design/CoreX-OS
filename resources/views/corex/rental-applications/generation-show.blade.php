{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Reopen/resubmit, 2026-09-08 — read-only view of exactly what an applicant
     submitted and signed at ONE sealed generation. Reads only from the
     append-only RentalApplicationGeneration snapshot passed in as $sealed —
     never from the live $rentalApplication row, which may since have moved
     on. This is the "read-only signed view" Johan's non-negotiable requires:
     evidence of what was signed at each point, untouchable after the fact. --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full" style="max-width: 56rem; margin: 0 auto; padding: 1rem;">

    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-sm font-bold" style="color: var(--text-primary);">
                Submission {{ $sealed->generation }} of {{ $latestGeneration }}
                — {{ $rentalApplication->full_name ?: $rentalApplication->contact?->full_name }}
            </h1>
            <p class="text-xs" style="color: var(--text-muted);">
                Signed and submitted {{ $sealed->submitted_at->format('d M Y, H:i') }}
                @if($sealed->ip_address) from {{ $sealed->ip_address }} @endif
            </p>
        </div>
        <a href="{{ route('corex.rental-applications.review', $rentalApplication) }}" class="text-xs underline" style="color: var(--ds-blue, #2563eb);">
            Back to review
        </a>
    </div>

    @unless($isLatest)
        <div class="mb-4 rounded-md px-3 py-2 text-xs" style="background: var(--ds-amber-soft, #fffbeb); border: 1px solid var(--ds-amber, #f59e0b); color: var(--text-primary);">
            This is a PREVIOUS submission — the applicant has since reopened and resubmitted a newer version
            (submission {{ $latestGeneration }}). This view is read-only history and cannot be edited.
        </div>
    @endunless

    <div class="rounded-md" style="border: 1px solid var(--border); background: var(--surface);">
        <div class="px-3 py-2 text-xs font-semibold" style="border-bottom: 1px solid var(--border); color: var(--text-secondary);">
            What was submitted
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 px-3 py-3">
            @foreach($sealed->snapshot_json as $field => $value)
                @continue(is_null($value) || $value === '')
                <div class="text-xs py-1" style="border-bottom: 1px solid var(--border);">
                    <span style="color: var(--text-muted);">{{ \Illuminate\Support\Str::headline($field) }}:</span>
                    <span style="color: var(--text-primary);" class="font-medium">
                        @if(is_bool($value)) {{ $value ? 'Yes' : 'No' }}
                        @else {{ $value }}
                        @endif
                    </span>
                </div>
            @endforeach
        </div>
    </div>

    <div class="rounded-md mt-4" style="border: 1px solid var(--border); background: var(--surface);">
        <div class="px-3 py-2 text-xs font-semibold" style="border-bottom: 1px solid var(--border); color: var(--text-secondary);">
            Signatures for this submission
        </div>
        <div class="flex flex-col gap-1 px-3 py-3">
            @forelse($signatures as $signature)
                {{-- Text only, matching show.blade.php / view-readonly.blade.php's
                     existing convention elsewhere in this module — neither of
                     those renders the signature image inline either (the
                     'local' disk isn't web-servable without a dedicated
                     streaming route, which nothing else in this module has
                     built either; pdf.blade.php's own img tag only works
                     because dompdf reads the local filesystem path directly,
                     server-side, never over HTTP). --}}
                <div class="text-xs">
                    ✓ {{ \Illuminate\Support\Str::headline($signature->kind) }} — signed {{ $signature->signed_at->format('d M Y, H:i') }}
                </div>
            @empty
                <p class="text-xs" style="color: var(--text-muted);">No signatures recorded for this submission.</p>
            @endforelse
        </div>
    </div>

</div>
@endsection
