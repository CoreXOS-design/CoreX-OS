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

    §17.4.5 (maintenance flow, Build 1) — the grid gains COST and MARGIN columns, and a small
    label under the selling price saying how it was arrived at ("set by hand / +20 % line /
    parts 20 % / job 15 % / catalogue / agency default / no markup applied"). Cost and margin
    exist in the markup ONLY when $showCost (the viewer holds rental_job_cards.view_costs) —
    absent, not hidden. Only ACCEPTED lines arrive here: a crew's pending lines have their own
    block (_pricing-panel).

    Expects: $lines (Collection<RentalJobCardLine>), $pricesOn (bool),
    $vat (array, RentalJobCardVatService::breakdown() shape), $jobCard,
    $canEditLines (bool — card still open), $catalogueItemTypes,
    $catalogueUnits, $vatTypes; optional $showCost (bool), $canPrice (bool), $costVat (array,
    RentalJobCardVatService::costBreakdown() shape — only when $showCost).
--}}
@if($lines->isEmpty())
    <p class="text-xs" style="color: var(--text-muted);">No lines yet.</p>
@else
    @php
        $showCost = ($showCost ?? false) && $pricesOn;
        $canPrice = ($canPrice ?? false) && $pricesOn;
        $rowGridStyle = \App\Support\RentalJobCardLineGrid::gridStyle($pricesOn, $vat['registered'], $showCost);
        $cell = \App\Support\RentalJobCardLineGrid::cellStyle();
        $money = fn ($v) => 'R' . number_format((float) $v, 2);
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
                            @if($showCost)
                                <span class="truncate" style="{{ $cell }}" title="{{ $line->cost_total !== null ? 'Cost total ' . $money($line->cost_total) : 'No cost recorded' }}">{{ $line->unit_cost !== null ? $money($line->unit_cost) : '—' }}</span>
                            @endif
                            {{-- SELLING + how it was arrived at (§17.4.5). A line with no price yet shows a dash and blocks "Send to owner as quote". --}}
                            <span style="display:block; min-width:0; overflow:hidden; {{ $cell }}">
                                <span style="display:block; white-space:nowrap; text-overflow:ellipsis; overflow:hidden;">{{ $line->unit_price !== null ? $money($line->unit_price) : '—' }}</span>
                                @php $basisLabel = \App\Services\Rentals\RentalPricingService::basisLabel($line, $jobCard); @endphp
                                @if($basisLabel !== '')
                                    <span title="{{ $basisLabel }}" style="display:block; white-space:nowrap; text-overflow:ellipsis; overflow:hidden; font-size:10px; color: var(--text-muted);" data-selling-basis>{{ $basisLabel }}</span>
                                @elseif($line->line_total === null)
                                    <span style="display:block; font-size:10px; color: var(--ds-crimson);" data-selling-basis>needs a price</span>
                                @endif
                            </span>
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
                                    {{-- label only in the cell (the 84px column cannot hold "Standard (15%)" at 1366 wide); the rate is in the tooltip --}}
                                    {{ $vatTypeLabel }}
                                </span>
                            @endif
                            @if($showCost)
                                {{-- Margin on the EXCL-VAT figures (§17.2). A legacy line with no cost shows "—" — no cost is ever invented. --}}
                                @php
                                    $costFig = $costVat['lineFigures'][$line->id] ?? null;
                                    $sellExcl = $line->line_total === null ? null : ($vat['registered'] ? ($vat['lineFigures'][$line->id]['excl'] ?? null) : (float) $line->line_total);
                                    $marginR = ($costFig && $sellExcl !== null) ? round($sellExcl - (float) $costFig['excl'], 2) : null;
                                    $marginPct = ($marginR !== null && $sellExcl > 0) ? round($marginR / $sellExcl * 100, 1) : null;
                                @endphp
                                <span class="truncate" style="{{ $cell }}" data-line-margin
                                      title="{{ $marginR === null ? ($line->cost_total === null ? 'No cost recorded' : 'No price yet') : 'Margin ' . $money($marginR) . ($marginPct !== null ? ' (' . $marginPct . ' % of selling), excl VAT' : ', excl VAT') }}">{{ $marginR !== null ? $money($marginR) : '—' }}</span>
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
                            'showCost' => $showCost, 'canPrice' => $canPrice,
                        ])
                    </template>
                @endif
                @endpermission
            </div>
        @endforeach
    </div>
@endif
