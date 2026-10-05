{{--
    2026-10-05 rebuild — one task's own parts & labour lines, OR the
    General group's lines. Shared partial so a task's table and the
    General group's table never drift from each other.

    Expects: $lines (Collection<RentalJobCardLine>), $pricesOn (bool),
    $vat (array, RentalJobCardVatService::breakdown() shape), $jobCard.
--}}
@if($lines->isEmpty())
    <p class="text-xs" style="color: var(--text-muted);">No lines yet.</p>
@else
<table class="w-full text-xs">
    <thead>
        <tr style="color: var(--text-muted);">
            <th class="text-left py-1">Description</th>
            <th class="text-left py-1">Type</th>
            @if($pricesOn)
                <th class="text-left py-1">Qty</th>
                <th class="text-left py-1">Unit price</th>
                <th class="text-left py-1">Line total</th>
                @if($vat['registered'])
                    <th class="text-left py-1">VAT type</th>
                @endif
            @endif
            <th></th>
        </tr>
    </thead>
    <tbody>
        @foreach($lines as $line)
            <tr style="border-top: 1px solid var(--border);">
                <td class="py-1">{{ $line->description }}</td>
                <td class="py-1">{{ ucfirst($line->type) }}</td>
                @if($pricesOn)
                    <td class="py-1">{{ rtrim(rtrim(number_format((float) $line->quantity, 2), '0'), '.') }} {{ $line->unit }}</td>
                    <td class="py-1">{{ $line->unit_price !== null ? 'R' . number_format((float) $line->unit_price, 2) : '—' }}</td>
                    <td class="py-1">{{ $line->line_total !== null ? 'R' . number_format((float) $line->line_total, 2) : '—' }}</td>
                    @if($vat['registered'])
                        <td class="py-1">
                            {{ $line->vat_display_label ?? $line->vatType?->name ?? '—' }}
                            @if($line->vat_display_rate !== null)
                                <span style="color: var(--text-muted);">({{ rtrim(rtrim(number_format((float) $line->vat_display_rate, 2), '0'), '.') }}%)</span>
                            @endif
                        </td>
                    @endif
                @endif
                <td class="py-1 text-right">
                    @permission('rental_job_cards.create')
                    <form method="POST" action="{{ route('corex.rental-job-cards.lines.destroy', [$jobCard, $line]) }}" onsubmit="return confirm('Archive this line?');" class="inline">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
                    </form>
                    @endpermission
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
@endif
