{{--
    .ai/specs/leases.md §15.12.3 (Build L0) — the field map of an agency's own lease agreement: for each
    thing CoreX knows about a lease, which of THIS agreement's own fields holds it. A suggestion only
    pre-selects the dropdown; nothing is saved until Save. Unmapped lines are simply not carried.
    Expects: $rentalLeaseTemplate, $mapLines, $templateFieldNames, $savedMap, $monthToMonth, $suggestions.
--}}
@php
    $registry = config('lease-agreement-fields.fields', []);
    $requirable = config('lease-agreement-fields.requirable_groups', []);
    $groupTitles = ['lease' => 'Lease', 'parties' => 'Parties', 'agreement' => 'Agreement terms', 'notice' => 'Notice and early cancellation', 'schedule' => 'Schedule', 'calculated' => 'Worked out by CoreX'];
    $byGroup = collect($mapLines)->groupBy('group', preserveKeys: true);
@endphp
<form id="field-map" method="POST" action="{{ route('corex.rental-lease-templates.field-map.update', $rentalLeaseTemplate) }}" class="space-y-4 rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
    @csrf
    @method('PUT')

    <h2 class="text-sm font-semibold">Map fields</h2>

    @if(empty($templateFieldNames))
        <p class="text-xs" style="color: var(--ds-crimson);">No fields were found in this document, so there is nothing to map yet.</p>
    @endif

    @foreach($groupTitles as $group => $title)
        @if($byGroup->has($group))
            <div class="space-y-2">
                <div class="text-xs font-medium" style="color: var(--text-muted);">{{ $title }}</div>
                @foreach($byGroup[$group] as $key => $line)
                    @php
                        $saved = $savedMap[$key]['field'] ?? null;
                        $suggested = $saved === null && ($line['base'] === $key) ? ($suggestions[$key] ?? null) : null;
                        $current = old("map.$key.field", $saved ?? $suggested);
                        $canRequire = in_array($line['group'], $requirable, true);
                    @endphp
                    <div class="grid grid-cols-2 gap-2 items-center">
                        <label class="text-xs" for="map-{{ $key }}">{{ $line['label'] }}</label>
                        <div class="flex items-center gap-2">
                            <select id="map-{{ $key }}" name="map[{{ $key }}][field]" class="prop-select flex-1 text-xs">
                                <option value="">— not in this agreement —</option>
                                @foreach($templateFieldNames as $name)
                                    <option value="{{ $name }}" @selected($current === $name)>{{ $name }}</option>
                                @endforeach
                            </select>
                            @if($suggested !== null)
                                <span class="text-xs" style="color: var(--text-muted);">suggested</span>
                            @endif
                            @if($canRequire)
                                <label class="flex items-center gap-1 text-xs whitespace-nowrap">
                                    <input type="checkbox" name="map[{{ $key }}][required]" value="1" @checked(old("map.$key.required", $savedMap[$key]['required'] ?? false))> Required for signing
                                </label>
                            @endif
                        </div>
                    </div>
                    @if($line['group'] === 'schedule')
                        <div class="grid grid-cols-2 gap-2 items-center">
                            <label class="text-xs" for="map-{{ $key }}-label">Label shown to your agents</label>
                            <input id="map-{{ $key }}-label" type="text" name="map[{{ $key }}][label]" maxlength="100" value="{{ old("map.$key.label", $savedMap[$key]['label'] ?? '') }}" class="prop-input text-xs">
                        </div>
                    @endif
                    @error("map.$key.field")<div class="text-xs" style="color: var(--ds-crimson);">{{ $message }}</div>@enderror
                @endforeach
            </div>
        @endif
    @endforeach

    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="month_to_month" value="1" @checked(old('month_to_month', $monthToMonth))>
        This lease is month-to-month (it has no end date)
    </label>

    <button type="submit" class="corex-btn-primary text-xs">Save field map</button>
</form>
