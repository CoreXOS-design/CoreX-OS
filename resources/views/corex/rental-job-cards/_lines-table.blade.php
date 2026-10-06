{{--
    2026-10-05 rebuild — one task's own parts & labour lines, OR the
    General group's lines. Shared partial so a task's table and the
    General group's table never drift from each other.

    2026-10-05 round 2 (Johan QA1 finding #3) — rebuilt from a <table> onto
    the SAME CSS grid as _line-columns-header.blade.php/_add-line-row.blade.php
    (App\Support\RentalJobCardLineGrid) so existing rows, the header above
    them, and the add-line row below them are all guaranteed to line up —
    a <table>'s own browser-default column sizing could never match a
    fixed-track CSS grid's widths, which is why the header used to sit
    above the add-line row only and never actually aligned with anything.

    2026-10-05 round 4 (Johan, "a saved line cannot be changed") — each row
    now has an Edit control that swaps the row, in place, for the SAME
    fields the add row uses (_add-line-row.blade.php, mode 'edit') with
    Save / Cancel; validation errors reopen that row's editor with what the
    agent typed. The row's grid sits on an INNER div: Alpine's x-show hides
    by writing `display:none` and re-shows by removing the property, which
    would permanently erase an inline `display:grid` placed on the toggled
    element itself.

    `data-line-id` marks each row for the "stay where you were, changed line
    in view" restore (show.blade.php script). `data-keep-scroll` on every
    line form (archive here, add/edit in _add-line-row, restore in show) is
    what asks that script to remember the panels' scroll position across
    the redirect.

    §14.21 — a closed (completed/cancelled) card is read-only: $canEditLines
    false hides the pencil AND the archive ×.

    Expects: $lines (Collection<RentalJobCardLine>), $pricesOn (bool),
    $vat (array, RentalJobCardVatService::breakdown() shape), $jobCard,
    $canEditLines (bool — card still open), $catalogueItemTypes,
    $catalogueUnits, $vatTypes.
--}}
@if($lines->isEmpty())
    <p class="text-xs" style="color: var(--text-muted);">No lines yet.</p>
@else
    @php
        $rowGridStyle = \App\Support\RentalJobCardLineGrid::gridStyle($pricesOn, $vat['registered']);
        $cell = \App\Support\RentalJobCardLineGrid::cellStyle();
    @endphp
    <div class="space-y-1">
        @foreach($lines as $line)
            @php $editingOnLoad = (string) old('_edit_line_id') === (string) $line->id; @endphp
            <div data-line-id="{{ $line->id }}" x-data="{ editing: {{ $editingOnLoad ? 'true' : 'false' }} }"
                 style="{{ $loop->first ? '' : 'border-top: 1px solid var(--border);' }}">
                <div x-show="!editing">
                    <div style="{{ $rowGridStyle }}" class="py-1 text-xs">
                        <span class="truncate" title="{{ $line->code }}" style="color: var(--text-muted); font-family: monospace; {{ $cell }}">{{ $line->code ?? '—' }}</span>
                        <span class="truncate" title="{{ $line->description }}" style="{{ $cell }}">{{ $line->description }}</span>
                        <span class="truncate" style="{{ $cell }}">{{ ucfirst($line->type) }}</span>
                        @if($pricesOn)
                            <span class="truncate" style="{{ $cell }}">{{ $line->unit }}</span>
                            <span class="truncate" style="{{ $cell }}">{{ rtrim(rtrim(number_format((float) $line->quantity, 2), '0'), '.') }}</span>
                            <span class="truncate" style="{{ $cell }}">{{ $line->unit_price !== null ? 'R' . number_format((float) $line->unit_price, 2) : '—' }}</span>
                            @if($vat['registered'])
                                {{-- §14.25 — the line's EFFECTIVE VAT type, resolved through the VAT service and keyed by line id
                                     (breakdown() writes its vat_display_* attributes onto $jobCard->lines, which are NOT the instances
                                     this partial iterates, so those were always empty and a line with no VAT type of its own showed "—").
                                     Wording = the add-line select's: Standard / None / Custom. --}}
                                @php
                                    $fig = $vat['lineFigures'][$line->id] ?? null;
                                    $vatTypeLabel = $fig['type_label'] ?? app(\App\Services\Rentals\RentalJobCardVatService::class)->effectiveTypeLabel($line);
                                    $vatRate = $fig['rate'] ?? null;
                                @endphp
                                <span class="truncate" title="{{ $vatTypeLabel }}{{ $vatRate ? ' (' . rtrim(rtrim(number_format((float) $vatRate, 2), '0'), '.') . '%)' : '' }}" style="{{ $cell }}">
                                    {{ $vatTypeLabel }}
                                    @if($vatRate)
                                        <span style="color: var(--text-muted);">({{ rtrim(rtrim(number_format((float) $vatRate, 2), '0'), '.') }}%)</span>
                                    @endif
                                </span>
                            @endif
                        @endif
                        <span class="whitespace-nowrap" style="display:inline-flex; justify-content:flex-end; align-items:center; gap:0;">
                            @permission('rental_job_cards.create')
                            @if($canEditLines)
                                <button type="button" @click="editing = true" title="Edit line" aria-label="Edit line" style="{{ \App\Support\RentalJobCardLineGrid::iconButtonStyle('var(--brand-icon, #0ea5e9)') }}">&#9998;</button>
                            <form method="POST" action="{{ route('corex.rental-job-cards.lines.destroy', [$jobCard, $line]) }}" onsubmit="return confirm('Archive this line?');" class="inline" style="display:inline-flex;" data-keep-scroll>
                                @csrf
                                @method('DELETE')
                                <button type="submit" title="Archive" aria-label="Archive" style="{{ \App\Support\RentalJobCardLineGrid::iconButtonStyle('var(--ds-red, #dc2626)') }}">&times;</button>
                            </form>
                            @endif
                            @endpermission
                        </span>
                    </div>
                </div>
                @permission('rental_job_cards.create')
                @if($canEditLines)
                    <template x-if="editing">
                        @include('corex.rental-job-cards._add-line-row', [
                            'mode' => 'edit', 'line' => $line, 'jobCard' => $jobCard,
                            'catalogueItemTypes' => $catalogueItemTypes, 'catalogueUnits' => $catalogueUnits,
                            'pricesOn' => $pricesOn, 'vatTypes' => $vatTypes, 'vatRegistered' => $vat['registered'],
                        ])
                    </template>
                @endif
                @endpermission
            </div>
        @endforeach
    </div>
@endif
