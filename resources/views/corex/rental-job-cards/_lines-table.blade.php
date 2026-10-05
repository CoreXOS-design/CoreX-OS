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

    Expects: $lines (Collection<RentalJobCardLine>), $pricesOn (bool),
    $vat (array, RentalJobCardVatService::breakdown() shape), $jobCard.
--}}
@if($lines->isEmpty())
    <p class="text-xs" style="color: var(--text-muted);">No lines yet.</p>
@else
    @php
        $rowGridStyle = \App\Support\RentalJobCardLineGrid::gridStyle($pricesOn, $vat['registered']);
    @endphp
    <div class="space-y-1">
        @foreach($lines as $line)
            <div style="{{ $rowGridStyle }}{{ $loop->first ? '' : ' border-top: 1px solid var(--border);' }}" class="py-1 text-xs">
                <span class="truncate" title="{{ $line->code }}" style="color: var(--text-muted); font-family: monospace;">{{ $line->code ?? '—' }}</span>
                <span class="truncate">{{ $line->description }}</span>
                <span class="truncate">{{ ucfirst($line->type) }}</span>
                @if($pricesOn)
                    <span class="truncate">{{ $line->unit }}</span>
                    <span class="truncate">{{ rtrim(rtrim(number_format((float) $line->quantity, 2), '0'), '.') }}</span>
                    <span class="truncate">{{ $line->unit_price !== null ? 'R' . number_format((float) $line->unit_price, 2) : '—' }}</span>
                    @if($vat['registered'])
                        <span class="truncate" title="{{ $line->vat_display_label ?? $line->vatType?->name ?? '—' }}">
                            {{ $line->vat_display_label ?? $line->vatType?->name ?? '—' }}
                            @if($line->vat_display_rate !== null)
                                <span style="color: var(--text-muted);">({{ rtrim(rtrim(number_format((float) $line->vat_display_rate, 2), '0'), '.') }}%)</span>
                            @endif
                        </span>
                    @endif
                @endif
                <span class="text-right">
                    @permission('rental_job_cards.create')
                    <form method="POST" action="{{ route('corex.rental-job-cards.lines.destroy', [$jobCard, $line]) }}" onsubmit="return confirm('Archive this line?');" class="inline">
                        @csrf
                        @method('DELETE')
                        <button type="submit" title="Archive" aria-label="Archive" class="text-xs" style="color: var(--ds-red, #dc2626);">&times;</button>
                    </form>
                    @endpermission
                </span>
            </div>
        @endforeach
    </div>
@endif
