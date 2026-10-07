{{--
    Decision 2 of the structured-address-matching build (.ai/specs/structured-address-matching.md §7):
    promote found a property that is only a POSSIBLE match (a neighbouring suburb, a different street type,
    a missing unit/portion). It is never linked silently and a second property is never created beside it
    silently — the agent sees both side by side and answers "same" or "different". No Alpine: plain server-
    rendered markup (the form id matches the exact-match form so ticked contact numbers still post with it).

    Needs: $tp (TrackedProperty), $possible (list of ['property','reason','columns','matched_on','panel'])
    Optional: $promoteAction (defaults to the Deeds screen's promote route).
--}}
@php
    $columnLabels = ['lpi' => 'LPI code', 'erf' => 'Erf', 'portion' => 'Portion', 'scheme' => 'Scheme / complex', 'unit' => 'Unit', 'suburb' => 'Suburb', 'street' => 'Street', 'number' => 'Street number', 'type' => 'Street type', 'gps' => 'GPS'];
    $verdictMark = fn (string $v) => match ($v) {
        'agree' => ['✓', 'var(--ds-green, #059669)'],
        'partial', 'neighbour', 'compatible' => ['≈', 'var(--ds-amber, #f59e0b)'],
        'differ', 'differ_number', 'differ_scheme_number' => ['≠', 'var(--ds-crimson, #c41e3a)'],
        default => ['–', 'var(--text-muted)'],
    };
@endphp
<div class="text-xs rounded-md p-3 basis-full" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 35%, var(--border)); color: var(--text-secondary);">
    <div class="font-semibold mb-1" style="color: var(--text-primary);">
        {{ count($possible) === 1 ? 'A property that may be the same is already on file' : count($possible) . ' properties that may be the same are already on file' }}
    </div>
    <div class="mb-2">Nothing has been linked and nothing has been created. Look at them side by side, then choose.</div>

    <form id="promote-form-{{ $tp->id }}" method="POST" action="{{ $promoteAction ?? route('corex.deeds-capture.promote', $tp->id) }}"
          onsubmit="return confirm('Go ahead with your choice? Any ticked contact numbers below will be added too.');">
        @csrf
        @foreach($possible as $i => $cand)
            @php $p = $cand['property']; @endphp
            <div class="rounded-md p-3 mb-2" style="background: var(--surface-2); border: 1px solid var(--border);">
                <label class="flex items-start gap-2" style="cursor: {{ count($possible) > 1 ? 'pointer' : 'default' }};">
                    @if(count($possible) > 1)
                        <input type="radio" name="possible_property_id" value="{{ $p->id }}" @checked($i === 0) class="mt-0.5">
                    @else
                        <input type="hidden" name="possible_property_id" value="{{ $p->id }}">
                    @endif
                    <span class="min-w-0">
                        <span class="font-semibold" style="color: var(--text-primary);">{{ $p->buildDisplayAddress() ?: ('Property #' . $p->id) }}{{ $p->suburb ? ', ' . $p->suburb : '' }}</span>
                        <a href="{{ route('corex.properties.show', $p->id) }}" target="_blank" rel="noopener" class="no-underline ml-1" style="color: var(--brand-icon, #2563eb);">Open property →</a>
                        <span class="block mt-0.5">{{ $cand['reason'] }}</span>
                    </span>
                </label>

                <div class="flex flex-wrap gap-x-3 gap-y-0.5 mt-1.5" style="color: var(--text-muted);">
                    @foreach($cand['columns'] as $col => $verdict)
                        @continue(! isset($columnLabels[$col]) || in_array($verdict, ['missing'], true) && $col !== 'suburb')
                        @php [$mark, $markColor] = $verdictMark($verdict); @endphp
                        <span><span style="color: {{ $markColor }}; font-weight: 700;">{{ $mark }}</span> {{ $columnLabels[$col] }}@if($col === 'suburb' && $verdict === 'neighbour') <span>(neighbouring suburb)</span>@endif</span>
                    @endforeach
                </div>

                <div class="overflow-x-auto mt-2">
                    <table class="min-w-full">
                        <thead>
                            <tr>
                                <th class="text-left pb-1 pr-3" style="color: var(--text-muted); font-weight: 600;">Field</th>
                                <th class="text-left pb-1 pr-3" style="color: var(--text-muted); font-weight: 600;">On file</th>
                                <th class="text-left pb-1" style="color: var(--text-muted); font-weight: 600;">This capture</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($cand['panel']['rows'] ?? [] as $row)
                                @php
                                    $rowColor = match ($row['strength']) {
                                        'strong' => 'var(--ds-green, #059669)',
                                        'weak' => 'var(--ds-amber, #f59e0b)',
                                        'differs' => 'var(--ds-crimson, #c41e3a)',
                                        default => 'var(--text-muted)',
                                    };
                                @endphp
                                <tr style="border-top: 1px solid var(--border);">
                                    <td class="py-1 pr-3 align-top" style="color: var(--text-secondary); white-space: nowrap;">{{ $row['label'] }}</td>
                                    <td class="py-1 pr-3 align-top" style="color: {{ $rowColor }};">{{ $row['existing'] ?? 'Not recorded' }}</td>
                                    <td class="py-1 align-top" style="color: {{ $rowColor }};">{{ $row['scraped'] ?? 'Not recorded' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach

        <div class="mb-2">
            If different, why? (only used if you pick "Different property")
            <select name="reject_reason_code" class="ml-1 text-xs rounded-md px-1.5 py-1" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                @foreach(\App\Services\Prospecting\PropertyMatchDecisionService::REJECT_REASON_CODES as $code => $label)
                    <option value="{{ $code }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="submit" name="match_decision" value="same" class="text-xs font-semibold px-4 py-2 rounded-md text-white" style="background: var(--brand-button, #0ea5e9);">
                Same property — link to it
            </button>
            <button type="submit" name="match_decision" value="different" formnovalidate class="text-xs font-semibold px-4 py-2 rounded-md" style="background: transparent; border: 1px solid var(--border); color: var(--text-primary);">
                Different property — add as new
            </button>
        </div>
    </form>
</div>
