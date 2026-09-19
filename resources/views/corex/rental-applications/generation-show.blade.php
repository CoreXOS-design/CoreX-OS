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
        {{-- Regression walk, 2026-09-09 — found live: --text-primary is
             theme-dependent (light text in this app's dark theme), which is
             invisible against this box's always-light amber background.
             Fixed the same way the settings screen's own amber banner
             already does it (rental-applications.blade.php's qualifying-
             formula warning) — a fixed dark amber text colour that reads
             correctly regardless of the surrounding theme. --}}
        <div class="mb-4 rounded-md px-3 py-2 text-xs" style="background: var(--ds-amber-soft, #fffbeb); border: 1px solid var(--ds-amber, #b45309); color: var(--ds-amber, #b45309);">
            This is a PREVIOUS submission — the applicant has since reopened and resubmitted a newer version
            (submission {{ $latestGeneration }}). This view is read-only history and cannot be edited.
        </div>
    @endunless

    <div class="rounded-md" style="border: 1px solid var(--border); background: var(--surface);">
        <div class="px-3 py-2 text-xs font-semibold" style="border-bottom: 1px solid var(--border); color: var(--text-secondary);">
            What was submitted
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 px-3 py-3">
            @php
                // Regression walk, 2026-09-09 — found live: a date field
                // (e.g. occupation_date) round-trips through the JSON
                // snapshot as a full Carbon-cast ISO timestamp
                // ("2001-02-01T22:00:00.000000Z" — the time-of-day and 'Z'
                // are UTC-conversion noise Laravel's date cast adds on
                // serialization, not real data), which rendered raw and
                // unreadable. These three are the only date-typed fields in
                // RentalApplication::fieldValidationRules() — formatted
                // explicitly here rather than guessing by regex.
                $dateFields = ['current_rental_from', 'current_rental_to', 'occupation_date'];

                // .ai/specs/rental-application-field-config.md, generation-
                // level follow-up 2026-09-20 — THIS generation's own frozen
                // field config (RentalApplicationGeneration::seal()),
                // never today's live settings and never
                // rental_applications.field_config_snapshot (which only
                // ever holds the LATEST round's config). A generation sealed
                // before this column existed has field_config_snapshot ===
                // null — falls back to the exact pre-existing behaviour
                // (title-cased column name, JSON key order) below.
                //
                // Deliberately never gates on 'shown': a field hidden as of
                // THIS generation may still carry a genuine answer from an
                // earlier round (seal() freezes every known field's current
                // value regardless of whether that round's form actually
                // asked for it) — hidden governs decluttering an EMPTY
                // field, never suppressing a real one already on file. The
                // @continue below already drops every empty field, hidden
                // or not, so this only affects label/order for fields that
                // DO have a value.
                $genFieldConfig = $sealed->field_config_snapshot;
                $snapshotRows = collect($sealed->snapshot_json)
                    ->reject(fn ($value) => is_null($value) || $value === '')
                    ->map(function ($value, $field) use ($genFieldConfig) {
                        $cfg = $genFieldConfig[$field] ?? null;
                        return [
                            'field' => $field,
                            'value' => $value,
                            'label' => $cfg['label'] ?? \Illuminate\Support\Str::headline($field),
                            'order' => $cfg['order'] ?? 999,
                            // §7, piece (c)(3) — a custom yes_no field's
                            // answer is stored as the string "1"/"0", same
                            // as every checkbox-shaped value elsewhere in
                            // this module; only a custom field carries its
                            // own field_type in the frozen config, so a
                            // shipped field's $cfg simply has none.
                            'field_type' => $cfg['field_type'] ?? null,
                        ];
                    })
                    ->values()
                    ->sortBy('order');
            @endphp
            @foreach($snapshotRows as $row)
                @php [$field, $value] = [$row['field'], $row['value']]; @endphp
                <div class="text-xs py-1" style="border-bottom: 1px solid var(--border);">
                    <span style="color: var(--text-muted);">{{ $row['label'] }}:</span>
                    <span style="color: var(--text-primary);" class="font-medium">
                        @if(is_bool($value)) {{ $value ? 'Yes' : 'No' }}
                        @elseif($row['field_type'] === 'yes_no') {{ $value == '1' ? 'Yes' : 'No' }}
                        @elseif(in_array($field, $dateFields, true)) {{ \Illuminate\Support\Carbon::parse($value)->format('d M Y') }}
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
