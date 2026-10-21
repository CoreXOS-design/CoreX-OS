{{--
    AT-481 — the "Change" controls of one lease (spec "reOS review screen rebuild"): property, suburb, the people, wallet reference, agent.
    Shown inside a ready / failed line (opened with its summary) and under a failed lease's card. Every control saves the moment it is
    changed ([data-field] / [data-payload], see the page script). Expects $row, $agents, $defaults, $editable.
--}}
@php
    $p = $row->payload_json ?? [];
    $d = $row->decisions_json ?? [];
    $id = $row->id;
    $loc = $d['property'] ?? [];
    $existing = $row->existing_lease_id !== null;
    $cands = \App\Services\Rentals\TakeOnImport\Reos\ReosCandidateDetails::for((int) $row->agency_id, $loc['candidates'] ?? []);
    $linkId = (int) ($loc['link_id'] ?? 0);
    $choseNew = ($loc['choice'] ?? null) === 'new';
    $creating = $row->property_match_action === \App\Models\RentalTakeOnImportRow::ACTION_CREATE;
    $partyState = function (?array $m, array $dec) {
        if (! $m) return null;
        $act = $dec['action'] ?? 'none';
        if ($act === 'link') {
            return (int) ($dec['contact_id'] ?? 0) === (int) ($m['existing_contact_id'] ?? -1) ? 'link-auto' : 'link-other';
        }
        return in_array($act, ['create', 'skip'], true) ? $act : 'create';
    };
@endphp
<div class="grid gap-3 md:grid-cols-2 p-3" data-test="reos-panel">
    @if($existing)
        <p class="text-muted md:col-span-2">This lease is already in CoreX — only its terms are updated; its property, people and agents stay as they are.</p>
    @else
        {{-- Property --}}
        <div>
            <div class="font-medium">Property</div>
            @if(count($cands) > 0)
                <select id="row-{{ $id }}-property-choice" data-field="property_choice" class="prop-select w-full" @disabled(! $editable) data-test="property-choice">
                    @if($row->property_match_action === \App\Models\RentalTakeOnImportRow::ACTION_CHOOSE)<option value="" selected disabled>Pick the right property…</option>@endif
                    @foreach($cands as $c)
                        <option value="{{ $c['id'] }}" @selected($linkId === (int) $c['id'] && ! $choseNew)>{{ $c['label'] }}@if($c['suburb'] !== '') — {{ $c['suburb'] }}@endif</option>
                    @endforeach
                    <option value="new" @selected($choseNew)>None of these — create a new property</option>
                </select>
            @else
                <div class="text-muted">{{ $creating ? 'A new property is created.' : $row->property_match_label }}</div>
            @endif
            @if($creating)
                <div class="mt-1 relative" data-suburb-wrap>
                    @if(! empty($loc['suburb']))
                        <div data-test="suburb-chosen"><strong>{{ $loc['suburb'] }}</strong>@if(! empty($loc['town'])), {{ $loc['town'] }}@endif
                            @if($editable)<button type="button" class="underline text-muted" data-clear-suburb>change</button>@endif</div>
                    @elseif($editable)
                        <input type="text" id="row-{{ $id }}-suburb" data-suburb-input autocomplete="off" placeholder="Suburb of the new property…" class="prop-input w-full">
                        <div data-suburb-results class="hidden absolute z-20 corex-card p-1 w-full"></div>
                    @endif
                </div>
            @endif
        </div>

        {{-- Agent --}}
        <div>
            <div class="font-medium">Agent</div>
            <select id="row-{{ $id }}-agent" data-field="agent_user_id" class="prop-select w-full" @disabled(! $editable)>
                <option value="" @selected(empty($d['agent_user_id']))>Choose…</option>
                @foreach($agents as $a)
                    <option value="{{ $a['id'] }}" @selected((int) ($d['agent_user_id'] ?? 0) === $a['id'])>{{ $a['name'] }}</option>
                @endforeach
            </select>
            <div class="text-muted">reOS: {{ $p['agent_text'] ?? '—' }}</div>
        </div>

        {{-- Owner / tenant --}}
        @foreach([['owner', \App\Services\Rentals\TakeOnImport\Reos\ReosQuestions::match($row, 'owner'), 'Owner'], ['tenant', \App\Services\Rentals\TakeOnImport\Reos\ReosQuestions::match($row, 'tenant'), 'Tenant']] as [$party, $m, $label])
            @continue(! $m)
            @php $state = $partyState($m, $d[$party] ?? []); @endphp
            <div data-party="{{ $party }}">
                <div class="font-medium">{{ $label }}: {{ $m['name'] }}</div>
                <select data-field="{{ $party }}" data-auto-contact="{{ $m['existing_contact_id'] ?? '' }}" class="prop-select w-full" @disabled(! $editable)>
                    @if(($m['action'] ?? '') === 'match')<option value="link-auto" @selected($state === 'link-auto')>Use {{ $m['matched_name'] ?? ($m['label'] ?? 'the contact already in CoreX') }}</option>@endif
                    @if($state === 'link-other')<option value="link-other" selected>Linked: {{ ($d[$party]['label'] ?? 'chosen contact') }}</option>@endif
                    <option value="create" @selected($state === 'create')>New contact</option>
                    <option value="skip" @selected($state === 'skip')>Leave out</option>
                </select>
                @if($editable)
                    <div class="mt-1 relative" data-contact-wrap="{{ $party }}">
                        <button type="button" class="underline text-muted" data-open-contact-search>Pick another contact…</button>
                        <div data-contact-panel class="hidden mt-1">
                            <input type="text" data-contact-input autocomplete="off" placeholder="Name, phone or e-mail…" class="prop-input w-full">
                            <div data-contact-results class="corex-card p-1"></div>
                        </div>
                    </div>
                @endif
                @include('corex.rentals.take-on-import._reos-people', ['row' => $row, 'party' => $party, 'm' => $m, 'state' => $state, 'd' => $d, 'editable' => $editable])
            </div>
        @endforeach

        <div>
            <label class="font-medium" for="row-{{ $id }}-wallet">Tenant wallet reference</label>
            <input type="text" id="row-{{ $id }}-wallet" data-field="tenant_wallet_ref" maxlength="100" value="{{ $d['tenant_wallet_ref'] ?? '' }}" class="prop-input w-full" @disabled(! $editable)>
        </div>
    @endif
</div>
