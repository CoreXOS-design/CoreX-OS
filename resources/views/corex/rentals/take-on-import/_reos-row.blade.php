{{--
    AT-479 — one lease on the reOS review screen (spec "reOS Tenancy Schedule import" R4/R5). Drawn by the page AND re-drawn by
    ReosTakeOnApiController after every change (the JSON answer carries this HTML), so what is on screen is what is saved.
    Expects: $row (RentalTakeOnImportRow), $run, $agents (list of {id,name}), $canManage (bool).
    Plain markup, no Alpine: the page's own script listens for [data-field] changes (event delegation on the table).
--}}
@php
    $p = $row->payload_json ?? [];
    $d = $row->decisions_json ?? [];
    $imported = $row->status === 'confirmed';
    $fileError = $row->status === 'error';
    $editable = $canManage && ! $imported && ! $fileError;
    $existing = $row->existing_lease_id !== null;
    $matched = $row->property_match_action === 'match';
    $loc = $d['property'] ?? [];
    $owner = $row->landlord_match_json[1] ?? null;
    $tenant = $row->tenant_match_json[1] ?? null;
    $blockers = $row->blockers_json ?? [];
    $id = $row->id;
    $partyState = function (?array $m, array $dec) {
        if (! $m) return null;
        $act = $dec['action'] ?? 'none';
        if ($act === 'link') {
            return (int) ($dec['contact_id'] ?? 0) === (int) ($m['existing_contact_id'] ?? -1) ? 'link-auto' : 'link-other';
        }
        return in_array($act, ['create', 'skip'], true) ? $act : 'create';
    };
    $ownerState = $partyState($owner, $d['owner'] ?? []);
    $tenantState = $partyState($tenant, $d['tenant'] ?? []);
@endphp
<tr id="row-{{ $id }}" class="align-top" data-row-id="{{ $id }}" data-blocked="{{ $row->is_blocked ? 1 : 0 }}" data-selected="{{ $row->selected ? 1 : 0 }}"
    style="{{ $imported ? 'opacity:.75;' : '' }}">

    {{-- Tick --}}
    <td>
        <input type="checkbox" data-field="selected" aria-label="Import lease on row {{ $row->row_number }}"
               @checked($row->selected) @disabled(! $editable)>
    </td>

    {{-- The lease --}}
    <td>
        <div class="font-medium">Row {{ $row->row_number }}
            <span class="ds-badge {{ in_array(mb_strtolower((string) ($p['lease_status'] ?? '')), ['active','expiring'], true) ? 'ds-badge-success' : 'ds-badge-muted' }}">{{ $p['lease_status'] ?? '—' }}</span>
        </div>
        <div class="text-muted">
            R{{ isset($p['rent']) ? number_format((float) $p['rent'], 2) : '—' }}/month
            @if(isset($p['deposit']) && $p['deposit'] !== null) · deposit R{{ number_format((float) $p['deposit'], 2) }}@endif
        </div>
        <div class="text-muted">{{ $p['lease_start_date'] ?? '—' }} → {{ $p['lease_end_date'] ?? 'no end date' }}</div>
        <div class="text-muted">
            @if(! empty($p['lease_type']))Type: {{ $p['lease_type'] }}@endif
            @if(array_key_exists('rollover', $p) && $p['rollover'] !== null) · Rollover: {{ $p['rollover'] ? 'yes' : 'no' }}@endif
        </div>
        @if($imported)
            <span class="ds-badge ds-badge-success" data-test="row-imported">{{ $row->result_action === 'updated' ? 'Updated' : 'Imported' }}</span>
            @if($row->target_lease_id)<a href="{{ route('corex.leases.show', $row->target_lease_id) }}" class="underline">Open lease</a>@endif
        @elseif($existing && collect($blockers)->contains('code', 'archived_lease'))
            <span class="ds-badge ds-badge-danger" data-test="row-existing-archived">In CoreX, but archived</span>
        @elseif($existing)
            <span class="ds-badge ds-badge-info" data-test="row-existing">In CoreX already — will be updated</span>
        @endif
    </td>

    {{-- Property --}}
    <td>
        <div class="font-medium">{{ $p['address'] ?? '—' }}</div>
        @if($existing || $imported)
            <div class="text-muted">{{ $imported ? 'Property: ' . ($row->property_match_action === 'create' ? 'created' : 'existing') : 'The lease\'s property is left as it is.' }}</div>
        @else
            @php
                $cands = $loc['candidates'] ?? [];
                $choosing = $row->property_match_action === 'choose';
                $linkId = (int) ($loc['link_id'] ?? 0);
                $choseNew = ($loc['choice'] ?? null) === 'new';
            @endphp
            <span class="ds-badge {{ $matched ? 'ds-badge-success' : ($choosing ? 'ds-badge-info' : 'ds-badge-warning') }}" data-test="property-state">{{ $matched ? 'In CoreX' : ($choosing ? 'Pick the property' : 'Not in CoreX') }}</span>
            @if($matched)
                <div class="text-muted">{{ $row->property_match_label }}</div>
            @endif
            @if(count($cands) > 0 && $editable)
                {{-- The properties already in CoreX that fit this address, in any suburb. --}}
                <select id="row-{{ $id }}-property-choice" data-field="property_choice" class="prop-select mt-1" style="max-width:100%;" data-test="property-choice">
                    @if($choosing)<option value="" selected disabled>Pick the right property…</option>@endif
                    @foreach($cands as $c)
                        <option value="{{ $c['id'] }}" @selected($linkId === (int) $c['id'] && ! $choseNew)>{{ $c['label'] }}@if(($c['suburb'] ?? '') !== '') — {{ $c['suburb'] }}@endif</option>
                    @endforeach
                    <option value="new" @selected($choseNew)>None of these — create a new property</option>
                </select>
            @elseif($choosing)
                <div class="text-muted">{{ $row->property_match_label }}</div>
            @endif
            @if(! $matched && ! $choosing)
                @if($choseNew)<div class="text-muted">{{ $row->property_match_label }}</div>@endif
                <label class="flex items-center gap-1 mt-1">
                    <input type="checkbox" id="row-{{ $id }}-property-create" data-field="property_create" @checked($d['property']['create'] ?? true) @disabled(! $editable)>
                    Create this property
                </label>
                @if(($d['property']['create'] ?? true))
                    <div class="mt-1 relative" data-suburb-wrap>
                        @if(! empty($loc['suburb']))
                            <div data-test="suburb-chosen"><strong>{{ $loc['suburb'] }}</strong>@if(! empty($loc['town'])), {{ $loc['town'] }}@endif @if(! empty($loc['province'])) ({{ $loc['province'] }})@endif
                                @if($editable)<button type="button" class="underline text-muted" data-clear-suburb>change</button>@endif</div>
                        @elseif($editable)
                            <input type="text" id="row-{{ $id }}-suburb" data-suburb-input autocomplete="off" placeholder="Type the suburb…" class="prop-input" style="max-width:100%;">
                            <div data-suburb-results class="hidden absolute z-20 rounded-md p-1" style="background:var(--surface); border:1px solid var(--border); min-width:15rem;"></div>
                        @endif
                    </div>
                @endif
            @endif
        @endif
    </td>

    {{-- Owner / Tenant --}}
    @foreach([['owner', $owner, $ownerState, 'Owner'], ['tenant', $tenant, $tenantState, 'Tenant']] as [$party, $m, $state, $label])
        <td data-party="{{ $party }}">
            @if(! $m)
                <span class="text-muted">No {{ strtolower($label) }} named</span>
            @else
                <div class="font-medium">{{ $m['name'] }}</div>
                @if($existing || $imported)
                    <div class="text-muted">{{ $imported && $row->result_action !== 'updated' ? ($m['action'] === 'create' ? 'New contact created' : 'Existing contact used') : 'Left as it is.' }}</div>
                @else
                    <span class="ds-badge {{ ($m['action'] ?? '') === 'match' ? 'ds-badge-success' : 'ds-badge-warning' }}" data-test="{{ $party }}-state">{{ ($m['action'] ?? '') === 'match' ? 'In CoreX' : 'Not in CoreX' }}</span>
                    @if(($m['kind'] ?? 'person') !== 'person')<span class="ds-badge ds-badge-muted">{{ $m['kind'] === 'company' ? 'Company' : 'Joint' }}</span>@endif
                    @if(($m['action'] ?? '') === 'match')<div class="text-muted">{{ $m['label'] }}</div>@endif
                    {{-- Matched on a phone / e-mail under a different name than reOS gives: a warning, not a blocker. Only while the row
                         still uses that contact (creating a new one, leaving them out or linking another clears it). --}}
                    @if(! empty($m['name_flag']) && $m['name_flag'] === 'spelling' && $state === 'link-auto')
                        {{-- Only a spelling apart: muted grey, not counted in the amber total. --}}
                        <div class="mt-1 text-muted" data-test="{{ $party }}-name-flag" data-name-flag="spelling">Spelling differs — CoreX has “{{ $m['matched_name'] ?? '' }}”, reOS has “{{ $m['name'] }}”.</div>
                    @elseif(! empty($m['name_flag']) && $state === 'link-auto')
                        <div class="mt-1 rounded px-2 py-1" style="background: color-mix(in srgb, var(--ds-amber, #d97706) 15%, transparent); color: var(--ds-amber, #b45309);" data-test="{{ $party }}-name-flag" data-name-flag="{{ $m['name_flag'] }}">
                            @if($m['name_flag'] === 'person_for_company')
                                <strong>Matched a person</strong> (likely the company's contact person): CoreX has “{{ $m['matched_name'] ?? '' }}”, reOS has “{{ $m['name'] }}”.
                            @else
                                <strong>Name differs</strong> — CoreX has “{{ $m['matched_name'] ?? '' }}”, reOS has “{{ $m['name'] }}”. Check it is the same person.
                            @endif
                        </div>
                    @endif
                    @if($editable)
                        <select data-field="{{ $party }}" data-auto-contact="{{ $m['existing_contact_id'] ?? '' }}" class="prop-select mt-1" style="max-width:100%;">
                            @if(($m['action'] ?? '') === 'match')<option value="link-auto" @selected($state === 'link-auto')>Use the contact already in CoreX</option>@endif
                            @if($state === 'link-other')<option value="link-other" selected>Linked: {{ ($d[$party]['label'] ?? 'chosen contact') }}</option>@endif
                            <option value="create" @selected($state === 'create')>Create a new contact</option>
                            <option value="skip" @selected($state === 'skip')>Leave out</option>
                        </select>
                        <div class="mt-1 relative" data-contact-wrap="{{ $party }}">
                            <button type="button" class="underline text-muted" data-open-contact-search>Link to another existing contact…</button>
                            <div data-contact-panel class="hidden mt-1">
                                <input type="text" data-contact-input autocomplete="off" placeholder="Name, phone or e-mail…" class="prop-input" style="max-width:100%;">
                                <div data-contact-results class="rounded-md p-1" style="background:var(--surface); border:1px solid var(--border);"></div>
                            </div>
                        </div>
                    @else
                        <div class="text-muted">{{ $state === 'skip' ? 'Left out' : ($state === 'create' ? 'Will be created' : 'Linked') }}</div>
                    @endif

                    {{-- The company matched a PERSON (a shared phone / e-mail) and the row still uses that person: the one-click is offered
                         here, before the company exists, as the full people block only applies to a company contact. --}}
                    @if($editable && ($m['name_flag'] ?? null) === 'person_for_company' && $state === 'link-auto')
                        <div class="mt-2 rounded-md p-2" style="background: var(--surface-2); border: 1px solid var(--border);" data-test="{{ $party }}-convert">
                            <button type="button" class="corex-btn-secondary text-xs" data-person-convert data-people-party="{{ $party }}" data-test="convert-to-company">Create the company and link {{ $m['matched_name'] ?? 'this person' }} as its contact person</button>
                        </div>
                    @endif

                    {{-- People for this company (spec R6a): shown for a party that is, or will be, a company contact. --}}
                    @if(\App\Services\Rentals\TakeOnImport\Reos\ReosCompanyPeople::isCompanyParty($row, $party))
                        @php
                            $addedPeople = $d[$party]['people'] ?? [];
                            $linkedPeople = \App\Services\Rentals\TakeOnImport\Reos\ReosCompanyPeople::existingPeople($row, $party);
                            $capacities = \App\Services\Rentals\TakeOnImport\Reos\ReosCompanyPeople::capacities();
                        @endphp
                        <div class="mt-2 rounded-md p-2" style="background: var(--surface-2); border: 1px solid var(--border);" data-test="{{ $party }}-people" data-people-party="{{ $party }}">
                            <div class="font-medium">People for this company</div>
                            @foreach($linkedPeople as $lp)
                                <div class="text-muted" data-test="linked-person">{{ $lp['name'] }}@if($lp['capacity']) — {{ $lp['capacity'] }}@endif <span>(already linked in CoreX)</span></div>
                            @endforeach
                            @foreach($addedPeople as $ap)
                                <div class="flex flex-wrap items-center gap-1 mt-1" data-test="added-person" data-person-key="{{ $ap['key'] }}">
                                    <span>{{ $ap['label'] ?? trim(($ap['first_name'] ?? '') . ' ' . ($ap['last_name'] ?? '')) }}@if(($ap['via'] ?? '') === 'matched_person') <span class="text-muted">(matched by phone / e-mail; reused)</span>@elseif(! empty($ap['contact_id'])) <span class="text-muted">(already in CoreX)</span>@else <span class="text-muted">(new)</span>@endif</span>
                                    @if($editable)
                                        <select data-person-capacity="{{ $ap['key'] }}" class="prop-select" style="max-width:100%;" aria-label="Role">
                                            @foreach($capacities as $cap)<option value="{{ $cap }}" @selected(($ap['capacity'] ?? '') === $cap)>{{ $cap }}</option>@endforeach
                                        </select>
                                        <button type="button" class="underline text-muted" data-person-remove="{{ $ap['key'] }}">remove</button>
                                    @else
                                        <span class="text-muted">— {{ $ap['capacity'] ?? '' }}</span>
                                    @endif
                                </div>
                            @endforeach
                            @if($linkedPeople === [] && $addedPeople === [])
                                <div class="text-muted">No people linked yet.</div>
                            @endif

                            @if($editable)
                                <div class="mt-2">
                                    <button type="button" class="underline" data-person-toggle>Add person</button>
                                    <div class="hidden mt-1 space-y-1" data-person-panel>
                                        <input type="text" data-person-search autocomplete="off" placeholder="Find a person already in CoreX…" class="prop-input" style="max-width:100%;">
                                        <div data-person-results class="rounded-md p-1" style="background:var(--surface); border:1px solid var(--border);"></div>
                                        <div class="text-muted">or add a new person:</div>
                                        <div class="flex flex-wrap gap-1">
                                            <input type="text" data-person-first placeholder="First name" class="prop-input" style="max-width:100%;">
                                            <input type="text" data-person-last placeholder="Last name" class="prop-input" style="max-width:100%;">
                                        </div>
                                        <div class="flex flex-wrap gap-1">
                                            <input type="text" data-person-phone placeholder="Phone" class="prop-input" style="max-width:100%;">
                                            <input type="email" data-person-email placeholder="E-mail" class="prop-input" style="max-width:100%;">
                                        </div>
                                        <div class="flex flex-wrap items-center gap-1">
                                            <select data-person-new-capacity class="prop-select" style="max-width:100%;" aria-label="Role">
                                                @foreach($capacities as $cap)<option value="{{ $cap }}">{{ $cap }}</option>@endforeach
                                            </select>
                                            <button type="button" class="corex-btn-secondary text-xs" data-person-add-new>Add person</button>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                @endif
            @endif
            @if($party === 'tenant' && ! $imported)
                <div class="mt-2">
                    <label class="text-muted" for="row-{{ $id }}-wallet">Tenant wallet reference</label>
                    <input type="text" id="row-{{ $id }}-wallet" data-field="tenant_wallet_ref" maxlength="100" value="{{ $d['tenant_wallet_ref'] ?? '' }}" class="prop-input" style="max-width:100%;" @disabled(! $editable)>
                </div>
            @endif
        </td>
    @endforeach

    {{-- Agent --}}
    <td>
        <div class="text-muted">reOS: {{ $p['agent_text'] ?? '—' }}</div>
        @if($imported)
            <div class="text-muted">Agent set on the lease.</div>
        @else
            <select id="row-{{ $id }}-agent" data-field="agent_user_id" class="prop-select mt-1" style="max-width:100%;" @disabled(! $editable)>
                <option value="" @selected(empty($d['agent_user_id']))>{{ $existing ? '— keep the lease\'s agents —' : 'Choose…' }}</option>
                @foreach($agents as $a)
                    <option value="{{ $a['id'] }}" @selected((int) ($d['agent_user_id'] ?? 0) === $a['id'])>{{ $a['name'] }}</option>
                @endforeach
            </select>
            @if(! empty($d['agent_note']) && empty($d['agent_user_id']))<div class="text-muted">{{ $d['agent_note'] }}</div>@endif
        @endif

        {{-- What stops it / what to know — kept in the agent cell (under the agent) so the row fits the page width. --}}
        <div class="mt-2 pt-2" style="border-top: 1px dashed var(--border);" data-test="row-notes">
        @if($row->import_error)
            <div class="text-red-600" data-test="import-error">{{ $row->import_error }}</div>
        @endif
        @foreach($blockers as $b)
            <div class="text-red-600" data-test="blocker" data-blocker-code="{{ $b['code'] }}">
                @if(! empty($b['field']))<a href="#row-{{ $id }}-{{ $b['field'] }}" data-focus="row-{{ $id }}-{{ $b['field'] }}" class="underline">{{ $b['message'] }}</a>@else{{ $b['message'] }}@endif
            </div>
        @endforeach
        @foreach(($row->warnings_json ?? []) as $w)
            <div class="text-amber-600">{{ $w }}</div>
        @endforeach
        @if(! $row->import_error && $blockers === [] && empty($row->warnings_json) && ! $imported)
            <span class="text-muted">Nothing to fix.</span>
        @endif
        </div>
    </td>
</tr>
